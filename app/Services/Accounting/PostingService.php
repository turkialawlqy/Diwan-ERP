<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\FiscalYearClosing;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Warehouse;
use App\Services\ActiveBranch;
use App\Services\Documents\DocumentNumberService;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way journal entries reach the ledger.
 *
 * Guarantees for every posted entry: balanced debits/credits, leaf + active
 * accounts of the same company, open fiscal period, sequential number,
 * monthly account balances updated in the same transaction, and a link back
 * to the source document. Posted entries are never edited or deleted — they
 * are reversed.
 */
class PostingService
{
    /** Largest automatic rounding difference absorbed into the rounding account. */
    protected const ROUNDING_TOLERANCE = 0.05;

    public function __construct(
        protected TenantManager $tenantManager,
        protected AccountMap $accounts,
        protected FiscalCalendar $calendar,
        protected DocumentNumberService $numbers,
    ) {}

    /**
     * The branch a source document belongs to: its own, its parent's (a payment's invoice),
     * or its warehouse's (stock documents).
     */
    protected function branchOf(?Model $source): ?int
    {
        if (! $source) {
            return null;
        }
        if ($id = $source->getAttribute('branch_id')) {
            return (int) $id;
        }
        if (method_exists($source, 'inheritedBranchId') && ($id = $source->inheritedBranchId())) {
            return (int) $id;
        }
        foreach (['warehouse_id', 'from_warehouse_id'] as $column) {
            if ($source->getAttribute($column) && ($id = Warehouse::withoutGlobalScopes()->whereKey($source->getAttribute($column))->value('branch_id'))) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Create (and by default post) a journal entry.
     *
     * $data: date, description, reference?, branch_id?, no_branch?, entry_type? (system),
     *        status? (posted|draft), source? (Model), created_by?,
     *        lines: [[account_id|role, debit, credit, description?, cost_center_id?, party_type?, party_id?], ...]
     *
     * A missing or null branch_id means "not given": the entry takes its source document's branch,
     * else the one chosen in the top bar. With no_branch => true it takes neither and stays without
     * a branch (closing or reversing entries that have none); a branch_id that is given still wins.
     */
    public function post(array $data): JournalEntry
    {
        $companyId = $data['company_id'] ?? $this->tenantManager->getTenantId();
        if (! $companyId) {
            throw new AccountingException('لا يمكن الترحيل بدون منشأة محددة.');
        }

        $date = Carbon::parse($data['date'] ?? now())->startOfDay();
        $status = $data['status'] ?? JournalEntry::STATUS_POSTED;
        $entryType = $data['entry_type'] ?? 'system';
        $lines = $this->normaliseLines($this->convert($data['lines'] ?? [], (float) ($data['rate'] ?? 1)), $companyId, $entryType !== 'manual');

        // Cost-centre / project policy applies when the entry is posted (a draft may stay incomplete).
        $this->validateLines($lines, $companyId, $status === JournalEntry::STATUS_POSTED);

        return DB::transaction(function () use ($data, $companyId, $date, $status, $entryType, $lines) {
            $period = $status === JournalEntry::STATUS_POSTED
                ? $this->calendar->assertOpen($date, $companyId)
                : $this->calendar->periodFor($date, $companyId);

            $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
            $source = $data['source'] ?? null;
            $noBranch = ! empty($data['no_branch']) && ! isset($data['branch_id']);
            // The entry's branch: given, else its source document's, else the one chosen in the top bar.
            $branchId = $noBranch ? null : ($data['branch_id'] ?? $this->branchOf($source) ?? app(ActiveBranch::class)->forWrite());

            // A branch closed for the year (its closing entry exists) takes no more postings in that year,
            // even while the year itself and its periods are still open for the other branches.
            if ($status === JournalEntry::STATUS_POSTED && ! in_array($entryType, ['closing', 'reversal'], true)) { // reopening the year reverses the closing entries
                if ($branchId && FiscalYearClosing::withoutGlobalScopes()->where('company_id', $companyId)->where('fiscal_year_id', $period->fiscal_year_id)->where('branch_id', $branchId)->exists()) {
                    throw new AccountingException('هذا الفرع أُقفل لهذه السنة المالية؛ لا يمكن الترحيل عليه بتاريخ '.$date->toDateString().'.');
                }
            }

            $attributes = [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'entry_number' => $this->numbers->next('journal_entry', $date, $companyId),
                'date' => $date->toDateString(),
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? '',
                'total_debit' => $totalDebit,
                'total_credit' => $totalDebit,
                'status' => $status,
                'entry_type' => $entryType,
                'source_type' => $source ? $source->getMorphClass() : ($data['source_type'] ?? null),
                'source_id' => $source ? $source->getKey() : ($data['source_id'] ?? null),
                'fiscal_period_id' => $period->id,
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
                'posted_at' => $status === JournalEntry::STATUS_POSTED ? now() : null,
                'posted_by' => $status === JournalEntry::STATUS_POSTED ? auth()->id() : null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ];

            // On creation BranchScoped gives an empty branch the top bar's (and refuses one under "all branches").
            $entry = $noBranch
                ? BranchScopeSwitch::off(fn () => JournalEntry::withoutGlobalScopes()->create($attributes))
                : JournalEntry::withoutGlobalScopes()->create($attributes);

            foreach ($lines as $line) {
                JournalEntryLine::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'party_type' => $line['party_type'] ?? null,
                    'party_id' => $line['party_id'] ?? null,
                    'date' => $date->toDateString(),
                    'description' => $line['description'] ?? null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            if ($status === JournalEntry::STATUS_POSTED) {
                $this->applyBalances($companyId, $date, $lines, 1);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Post a draft entry (re-validated against the current rules).
     */
    public function postDraft(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            throw new AccountingException('القيد مرحّل مسبقاً.');
        }

        $lines = $entry->lines()->get()->map(fn ($l) => [
            'account_id' => $l->account_id,
            'debit' => (float) $l->debit,
            'credit' => (float) $l->credit,
            'cost_center_id' => $l->cost_center_id,
            'project_id' => $l->project_id,
            // Distribution lines of a hand-made entry follow the dimension policy when it is finally posted.
            'dimensions' => in_array($entry->entry_type, ['manual', 'adjustment'], true),
        ])->all();

        $this->validateLines($lines, $entry->company_id);

        return DB::transaction(function () use ($entry, $lines) {
            // Locked and checked again: two clicks on «ترحيل» must not apply the balances twice.
            $entry = JournalEntry::withoutGlobalScopes()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== JournalEntry::STATUS_DRAFT) {
                throw new AccountingException('القيد مرحّل مسبقاً.');
            }
            $period = $this->calendar->assertOpen($entry->date, $entry->company_id);

            // As in post(): a branch closed for the year takes no more postings in that year, drafts included.
            if ($entry->branch_id && FiscalYearClosing::withoutGlobalScopes()->where('company_id', $entry->company_id)->where('fiscal_year_id', $period->fiscal_year_id)->where('branch_id', $entry->branch_id)->exists()) {
                throw new AccountingException('هذا الفرع أُقفل لهذه السنة المالية؛ لا يمكن الترحيل عليه بتاريخ '.$entry->date->toDateString().'.');
            }

            $entry->update([
                'status' => JournalEntry::STATUS_POSTED,
                'fiscal_period_id' => $period->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->applyBalances($entry->company_id, Carbon::parse($entry->date), $lines, 1);

            return $entry;
        });
    }

    /**
     * Reverse a posted entry with a mirror entry (the original stays in the
     * ledger, marked "reversed").
     */
    public function reverse(JournalEntry $entry, CarbonInterface|string|null $date = null, ?string $description = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $description) {
            $entry = JournalEntry::withoutGlobalScopes()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->status !== JournalEntry::STATUS_POSTED) {
                throw new AccountingException("لا يمكن عكس القيد {$entry->entry_number} لأنه غير مرحّل أو معكوس مسبقاً.");
            }

            // Reverse in the original period when it is still open, otherwise today.
            $reverseDate = $date ? Carbon::parse($date) : Carbon::parse($entry->date);
            if (! $date && ! $this->calendar->periodFor($reverseDate, $entry->company_id)->isOpen()) {
                $reverseDate = now();
            }

            $reversal = $this->post([
                'company_id' => $entry->company_id,
                'branch_id' => $entry->branch_id,
                'no_branch' => $entry->branch_id === null, // the mirror stays where its original is
                'date' => $reverseDate,
                'reference' => $entry->entry_number,
                'description' => $description ?? 'عكس القيد '.$entry->entry_number.' - '.$entry->description,
                'entry_type' => 'reversal',
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'reversal_of_id' => $entry->id,
                'lines' => $entry->lines()->withoutGlobalScopes()->get()->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'debit' => (float) $l->credit,
                    'credit' => (float) $l->debit,
                    'description' => $l->description,
                    'cost_center_id' => $l->cost_center_id,
                    'project_id' => $l->project_id,
                    'party_type' => $l->party_type,
                    'party_id' => $l->party_id,
                ])->all(),
            ]);

            $entry->update(['status' => JournalEntry::STATUS_REVERSED, 'reversed_by_id' => $reversal->id]);

            return $reversal;
        });
    }

    /**
     * Reverse every posted entry generated for a source document.
     */
    public function reverseSource(Model $source, ?string $description = null): void
    {
        JournalEntry::withoutGlobalScopes()
            ->where('company_id', $source->getAttribute('company_id'))
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => $this->reverse($entry, null, $description));
    }

    /**
     * Posted (non reversed) entries of a source document.
     */
    public function entriesFor(Model $source)
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $source->getAttribute('company_id'))
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Rebuild the aggregated balances of a company from its ledger lines.
     */
    public function rebuildBalances(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            DB::table('journal_entry_lines')
                ->where('company_id', $companyId)
                ->whereNull('date')
                ->update([
                    'date' => DB::raw('(SELECT journal_entries.date FROM journal_entries WHERE journal_entries.id = journal_entry_lines.journal_entry_id)'),
                ]);

            DB::table('account_balances')->where('company_id', $companyId)->delete();

            $periodExpr = DB::getDriverName() === 'sqlite'
                ? "CAST(strftime('%Y%m', jel.date) AS INTEGER)"
                : "CAST(DATE_FORMAT(jel.date, '%Y%m') AS UNSIGNED)";

            $rows = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->where('jel.company_id', $companyId)
                ->whereIn('je.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
                ->groupBy('jel.account_id', DB::raw($periodExpr))
                ->selectRaw("jel.account_id, {$periodExpr} as period, SUM(jel.debit) as debit, SUM(jel.credit) as credit")
                ->get();

            $now = now();
            foreach ($rows->chunk(500) as $chunk) {
                DB::table('account_balances')->insert($chunk->map(fn ($r) => [
                    'company_id' => $companyId,
                    'account_id' => $r->account_id,
                    'period' => (int) $r->period,
                    'debit' => round((float) $r->debit, 2),
                    'credit' => round((float) $r->credit, 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            $totals = DB::table('account_balances')
                ->where('company_id', $companyId)
                ->groupBy('account_id')
                ->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')
                ->get()
                ->keyBy('account_id');

            Account::withoutGlobalScopes()->where('company_id', $companyId)->get()
                ->each(function (Account $account) use ($totals) {
                    $t = $totals->get($account->id);
                    $account->forceFill(['balance' => $t ? $account->signedBalance((float) $t->debit, (float) $t->credit) : 0])->saveQuietly();
                });
        });
    }

    /**
     * Resolve roles, round, drop empty lines and (for system entries) absorb a
     * tiny rounding difference into the rounding account.
     */
    /**
     * Foreign-currency documents: amounts become base currency at the
     * document rate. Lines flagged 'base' (e.g. stock cost from the ledger)
     * are already in base currency.
     */
    protected function convert(array $lines, float $rate): array
    {
        if ($rate <= 0 || abs($rate - 1.0) < 1e-9) {
            return $lines;
        }

        $converted = [];
        foreach ($lines as $line) {
            if (empty($line['base'])) {
                foreach (['debit', 'credit'] as $side) {
                    if (isset($line[$side])) {
                        $line[$side] = round((float) $line[$side] * $rate, 2);
                    }
                }
                $line['_converted'] = true;
            }
            unset($line['base']);
            $converted[] = $line;
        }

        // Per-line rounding can leave a few halalas between the sides; put
        // them on the largest converted line so the entry stays exact.
        $difference = round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0) - (float) ($l['credit'] ?? 0), $converted)), 2);
        if ($difference != 0.0) {
            $side = $difference > 0 ? 'credit' : 'debit';
            $target = null;
            foreach ($converted as $i => $line) {
                if (! empty($line['_converted']) && (float) ($line[$side] ?? 0) > 0 && ($target === null || (float) $line[$side] > (float) $converted[$target][$side])) {
                    $target = $i;
                }
            }
            if ($target !== null) {
                $converted[$target][$side] = round((float) $converted[$target][$side] + abs($difference), 2);
            }
        }

        return array_map(function ($line) {
            unset($line['_converted']);

            return $line;
        }, $converted);
    }

    protected function normaliseLines(array $lines, int $companyId, bool $allowRounding): array
    {
        $normalised = [];

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            // A negative amount on one side is the same as a positive amount on the other.
            if ($debit < 0) {
                [$credit, $debit] = [$credit - $debit, 0.0];
            }
            if ($credit < 0) {
                [$debit, $credit] = [$debit - $credit, 0.0];
            }

            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            $accountId = $line['account_id'] ?? null;
            if (! $accountId && ! empty($line['role'])) {
                $accountId = $this->accounts->id($line['role'], $companyId);
                // A customer of a group with its own receivable account posts there.
                if ($line['role'] === 'receivables' && ($line['party_type'] ?? null) === 'customer' && ! empty($line['party_id'])) {
                    $accountId = app(PartyAccounts::class)->receivableFor((int) $line['party_id'], $companyId) ?: $accountId;
                }
            }

            $normalised[] = array_merge($line, [
                'account_id' => (int) $accountId,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        $difference = round(array_sum(array_column($normalised, 'debit')) - array_sum(array_column($normalised, 'credit')), 2);

        if ($allowRounding && $difference != 0.0 && abs($difference) <= self::ROUNDING_TOLERANCE) {
            $normalised[] = [
                'account_id' => $this->accounts->id('rounding', $companyId),
                'debit' => $difference < 0 ? abs($difference) : 0.0,
                'credit' => $difference > 0 ? $difference : 0.0,
                'description' => 'فرق تقريب',
            ];
        }

        return $normalised;
    }

    protected function validateLines(array $lines, int $companyId, bool $dimensionPolicy = true): void
    {
        if (count($lines) < 2) {
            throw new AccountingException('القيد يجب أن يحتوي على طرفين على الأقل (مدين ودائن).');
        }

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($debit - $credit) >= 0.005) {
            throw new AccountingException("القيد غير متوازن: إجمالي المدين {$debit} لا يساوي إجمالي الدائن {$credit}.");
        }

        foreach ($lines as $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                throw new AccountingException('لا يمكن أن يكون السطر مديناً ودائناً في نفس الوقت.');
            }
        }

        $accountIds = array_unique(array_column($lines, 'account_id'));
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $account = $accounts->get($line['account_id']);

            if (! $account) {
                throw new AccountingException('أحد الحسابات المحددة في القيد غير موجود.');
            }
            if ($account->is_parent) {
                throw new AccountingException("لا يمكن الترحيل على الحساب الرئيسي «{$account->code} - {$account->name_ar}». اختر حساباً فرعياً.");
            }
            if (! $account->is_active) {
                throw new AccountingException("الحساب «{$account->code} - {$account->name_ar}» موقوف.");
            }
            if ($account->requires_cost_center && empty($line['cost_center_id'])) {
                throw new AccountingException("الحساب «{$account->code} - {$account->name_ar}» يتطلب تحديد مركز تكلفة.");
            }
            if ($dimensionPolicy && ! empty($line['dimensions'])) {
                $policy = app(DimensionPolicy::class);
                foreach (DimensionPolicy::DIMENSIONS as $dimension => $label) {
                    if (empty($line["{$dimension}_id"]) && $policy->required($account, $dimension, $companyId)) {
                        throw new AccountingException("الحساب «{$account->code} - {$account->name_ar}» يتطلب تحديد {$label} حسب سياسة المنشأة.");
                    }
                }
            }
        }

        $costCenterIds = array_filter(array_unique(array_column($lines, 'cost_center_id')));
        if ($costCenterIds) {
            $found = CostCenter::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $costCenterIds)->count();
            if ($found !== count($costCenterIds)) {
                throw new AccountingException('أحد مراكز التكلفة المحددة غير صالح.');
            }
        }
    }

    /**
     * Add (sign = 1) or remove (sign = -1) the lines from the monthly balances
     * and the running account balance.
     */
    protected function applyBalances(int $companyId, Carbon $date, array $lines, int $sign): void
    {
        $period = (int) $date->format('Ym');
        $now = now();
        $grouped = [];

        foreach ($lines as $line) {
            $id = $line['account_id'];
            $grouped[$id]['debit'] = ($grouped[$id]['debit'] ?? 0) + $line['debit'];
            $grouped[$id]['credit'] = ($grouped[$id]['credit'] ?? 0) + $line['credit'];
        }

        // Sorted to keep a stable lock order between concurrent postings.
        ksort($grouped);

        $natures = Account::withoutGlobalScopes()->whereIn('id', array_keys($grouped))->pluck('nature', 'id');

        foreach ($grouped as $accountId => $amounts) {
            $debit = round($amounts['debit'] * $sign, 2);
            $credit = round($amounts['credit'] * $sign, 2);
            // Fixed-point literals (never scientific notation) for the SQL increments.
            $d = number_format($debit, 2, '.', '');
            $c = number_format($credit, 2, '.', '');

            $updated = DB::table('account_balances')
                ->where(['company_id' => $companyId, 'account_id' => $accountId, 'period' => $period])
                ->update([
                    'debit' => DB::raw('debit + '.$d),
                    'credit' => DB::raw('credit + '.$c),
                    'updated_at' => $now,
                ]);

            if (! $updated) {
                DB::table('account_balances')->insertOrIgnore([
                    'company_id' => $companyId, 'account_id' => $accountId, 'period' => $period,
                    'debit' => 0, 'credit' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('account_balances')
                    ->where(['company_id' => $companyId, 'account_id' => $accountId, 'period' => $period])
                    ->update(['debit' => DB::raw('debit + '.$d), 'credit' => DB::raw('credit + '.$c)]);
            }

            $delta = ($natures[$accountId] ?? 'debit') === 'debit' ? $debit - $credit : $credit - $debit;
            DB::table('accounts')->where('id', $accountId)->update(['balance' => DB::raw('balance + '.number_format($delta, 2, '.', ''))]);
        }
    }
}

<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\ZakatAssessment;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Month / year end adjustments: revaluation of open foreign-currency
 * receivables and payables, and the zakat assessment.
 */
class PeriodEndService
{
    /** Zakat rate on a lunar (Hijri) year, and its Gregorian-year equivalent (2.5% x 365/354). */
    public const ZAKAT_RATE_HIJRI = 2.5;

    public const ZAKAT_RATE_GREGORIAN = 2.5776;

    public function __construct(
        protected PostingService $posting,
        protected AccountingReports $reports,
        protected AccountMap $map,
        protected ExchangeRates $rates,
        protected TenantManager $tenants,
    ) {}

    // ------------------------------------------------------------------ FX revaluation

    /**
     * Open foreign-currency documents measured at the month-end rate: the
     * difference from the rate they were booked at.
     */
    public function fxPreview(Carbon $monthEnd): array
    {
        $monthEnd = $monthEnd->copy()->endOfMonth();
        $base = $this->rates->base();
        $rows = [];

        $documents = [
            ['receivable', Invoice::with('customer:id,name'), 'invoice_number', 'customer', 'customer_id'],
            ['payable', PurchaseInvoice::with('supplier:id,name'), 'invoice_number', 'supplier', 'supplier_id'],
        ];
        foreach ($documents as [$kind, $query, $number, $party, $partyKey]) {
            $query->whereNotNull('currency_code')->where('currency_code', '!=', $base)
                ->where('remaining_amount', '>', 0)->whereDate('invoice_date', '<=', $monthEnd)
                ->get()->each(function ($doc) use ($kind, $number, $party, $partyKey, $monthEnd, &$rows) {
                    $closing = $this->rates->rate($doc->currency_code, $monthEnd);
                    $remaining = (float) $doc->remaining_amount;
                    $difference = round($remaining * $closing - $remaining * (float) $doc->exchange_rate, 2);
                    if ($difference != 0.0) {
                        $rows[] = ['kind' => $kind, 'number' => $doc->{$number}, 'party_id' => $doc->{$partyKey}, 'party' => $doc->{$party}?->name,
                            'currency' => $doc->currency_code, 'remaining' => $remaining, 'book_rate' => (float) $doc->exchange_rate,
                            'closing_rate' => $closing, 'difference' => $difference];
                    }
                });
        }

        $effect = collect($rows)->sum(fn ($r) => $r['kind'] === 'receivable' ? $r['difference'] : -$r['difference']);

        return ['month' => $monthEnd->format('Y-m'), 'rows' => $rows, 'net_gain' => round($effect, 2), 'posted' => $this->revaluationEntry($monthEnd) !== null];
    }

    /**
     * Posts the unrealised difference at month end and reverses it on the
     * first day of the next month, so the realised difference booked when
     * the document is settled is never counted twice.
     */
    public function revalue(Carbon $monthEnd): ?JournalEntry
    {
        $monthEnd = $monthEnd->copy()->endOfMonth();
        if ($this->revaluationEntry($monthEnd)) {
            throw ValidationException::withMessages(['month' => 'تمت إعادة تقييم العملات لهذا الشهر مسبقاً.']);
        }
        if ($monthEnd->isFuture()) {
            throw ValidationException::withMessages(['month' => 'لا يمكن إعادة التقييم قبل نهاية الشهر.']);
        }

        $preview = $this->fxPreview($monthEnd);
        if (! $preview['rows']) {
            return null;
        }

        $lines = [];
        $net = 0.0;
        foreach (collect($preview['rows'])->groupBy(fn ($r) => $r['kind'].':'.$r['party_id']) as $rows) {
            $first = $rows->first();
            $amount = round($rows->sum('difference'), 2);
            if ($amount == 0.0) {
                continue;
            }
            $receivable = $first['kind'] === 'receivable';
            // A receivable worth more (or a payable costing less) is a gain.
            $gain = $receivable ? $amount : -$amount;
            $net += $gain;
            $lines[] = [
                'role' => $receivable ? 'receivables' : 'payables',
                'party_type' => $receivable ? 'customer' : 'supplier',
                'party_id' => $first['party_id'],
                // The control account moves by the gain side: up for a receivable, down for a payable.
                $gain > 0 ? 'debit' : 'credit' => abs($amount),
                'description' => 'إعادة تقييم '.($first['party'] ?? ''),
            ];
        }
        $net = round($net, 2);
        if (! $lines) {
            return null;
        }
        if ($net != 0.0) {
            $lines[] = ['role' => 'fx_difference', $net > 0 ? 'credit' : 'debit' => abs($net), 'description' => 'فروق إعادة تقييم غير محققة'];
        }

        return DB::transaction(function () use ($monthEnd, $lines) {
            $entry = $this->posting->post([
                'date' => $monthEnd,
                'reference' => 'FXR-'.$monthEnd->format('Ym'),
                'description' => 'إعادة تقييم أرصدة العملات الأجنبية - '.$monthEnd->format('Y-m'),
                'entry_type' => 'adjustment',
                'source_type' => 'fx_revaluation',
                'lines' => $lines,
            ]);
            $this->posting->reverse($entry, $monthEnd->copy()->addDay(), 'عكس إعادة تقييم العملات - '.$monthEnd->format('Y-m'));

            return $entry->fresh();
        });
    }

    protected function revaluationEntry(Carbon $monthEnd): ?JournalEntry
    {
        return JournalEntry::where('source_type', 'fx_revaluation')->whereNull('reversal_of_id')
            ->where('reference', 'FXR-'.$monthEnd->format('Ym'))->first();
    }

    // ------------------------------------------------------------------ Zakat

    /**
     * Zakat base by the net-assets method: equity + adjusted net profit +
     * non-current liabilities − net fixed assets, never below the adjusted
     * profit, on the Saudi/GCC ownership share.
     *
     * $options: calendar (gregorian|hijri), saudi_share (%), additions, deductions
     */
    public function zakatPreview(FiscalYear $year, array $options = []): array
    {
        $from = Carbon::parse($year->start_date)->startOfDay();
        $to = Carbon::parse($year->end_date)->endOfDay();

        $equityIds = Account::where('type', 'equity')->where('is_parent', false)->pluck('id')->all();
        $opening = $this->reports->totalsBetween(null, $from->copy()->subDay(), $equityIds);
        $movement = $this->reports->totalsBetween($from, $to, $equityIds, null, true);
        $equity = $this->credit($opening) + $this->credit($movement);

        $income = $this->reports->incomeStatement($from, $to);
        $zakatExpense = $this->credit($this->reports->totalsBetween($from, $to, array_filter([$this->map->find('zakat_expense')]), null, true)) * -1;
        $adjustedProfit = round((float) $income['net_profit'] + $zakatExpense, 2);

        $cumulative = $this->reports->totalsBetween(null, $to);
        $liabilityIds = $this->siblings(['end_of_service_provision', 'long_term_loans']);
        $longTerm = $this->credit(array_intersect_key($cumulative, array_flip($liabilityIds)));
        $assetIds = $this->siblings(['fixed_assets', 'accumulated_depreciation']);
        $fixedAssets = -$this->credit(array_intersect_key($cumulative, array_flip($assetIds)));

        $additions = round((float) ($options['additions'] ?? 0), 2);
        $deductions = round((float) ($options['deductions'] ?? 0), 2);
        $computed = round($equity + $adjustedProfit + $longTerm + $additions - $fixedAssets - $deductions, 2);
        $base = max($computed, $adjustedProfit, 0);

        $share = min(100, max(0, (float) ($options['saudi_share'] ?? 100)));
        $rate = ($options['calendar'] ?? 'gregorian') === 'hijri' ? self::ZAKAT_RATE_HIJRI : self::ZAKAT_RATE_GREGORIAN;
        $amount = round($base * $share / 100 * $rate / 100, 2);

        return [
            'year' => $year->only(['id', 'name', 'start_date', 'end_date', 'status']),
            'equity' => round($equity, 2),
            'net_profit' => (float) $income['net_profit'],
            'zakat_expense_added_back' => round($zakatExpense, 2),
            'adjusted_profit' => $adjustedProfit,
            'long_term_liabilities' => round($longTerm, 2),
            'fixed_assets' => round($fixedAssets, 2),
            'additions' => $additions,
            'deductions' => $deductions,
            'computed_base' => $computed,
            'base' => round($base, 2),
            'saudi_share' => $share,
            'rate' => $rate,
            'amount' => $amount,
            'assessment' => ZakatAssessment::where('fiscal_year_id', $year->id)->first(),
        ];
    }

    /** Dr Zakat expense / Cr Zakat payable at the year end (before closing the year). */
    public function postZakat(FiscalYear $year, array $options = []): ZakatAssessment
    {
        return DB::transaction(function () use ($year, $options) {
            if (ZakatAssessment::where('fiscal_year_id', $year->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['year' => 'سُجّل مخصص الزكاة لهذه السنة مسبقاً.']);
            }
            if ($year->status === 'closed') {
                throw ValidationException::withMessages(['year' => 'السنة مقفلة؛ أعد فتحها لتسجيل الزكاة قبل الإقفال.']);
            }

            $preview = $this->zakatPreview($year, $options);
            if ($preview['amount'] <= 0) {
                throw ValidationException::withMessages(['year' => 'لا يوجد وعاء زكوي موجب لهذه السنة.']);
            }

            $entry = $this->posting->post([
                'date' => Carbon::parse($year->end_date),
                'reference' => 'ZKT-'.$year->name,
                'description' => "مخصص الزكاة الشرعية للسنة {$year->name}",
                'entry_type' => 'adjustment',
                'source_type' => 'zakat',
                'lines' => [
                    ['role' => 'zakat_expense', 'debit' => $preview['amount']],
                    ['role' => 'zakat_payable', 'credit' => $preview['amount']],
                ],
            ]);

            return ZakatAssessment::create([
                'fiscal_year_id' => $year->id, 'base' => $preview['base'], 'rate' => $preview['rate'], 'amount' => $preview['amount'],
                'details' => collect($preview)->except(['year', 'assessment'])->all(), 'journal_entry_id' => $entry->id, 'created_by' => auth()->id(),
            ]);
        });
    }

    /** credit − debit of the given totals. */
    protected function credit(array $totals): float
    {
        return round(collect($totals)->sum(fn ($t) => (float) ($t['credit'] ?? 0) - (float) ($t['debit'] ?? 0)), 2);
    }

    /** Leaf accounts in the groups of the given roles (e.g. all fixed asset accounts). */
    protected function siblings(array $roles): array
    {
        $ids = array_filter(array_map(fn ($r) => $this->map->find($r), $roles));
        $parents = Account::whereIn('id', $ids)->pluck('parent_id')->filter()->all();

        return Account::where('is_parent', false)->where(fn ($q) => $q->whereIn('parent_id', $parents)->orWhereIn('id', $ids))->pluck('id')->all();
    }
}

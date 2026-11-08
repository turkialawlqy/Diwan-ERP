<?php

namespace App\Services\Projects;

use App\Models\Account;
use App\Models\Project;
use App\Models\ProjectAllocationRun;
use App\Models\ProjectCost;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\ActiveBranch;
use App\Services\Documents\DocumentNumberService;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Indirect (overhead) costs spread over the open projects. The pool is what the chosen expense accounts hold for the
 * period; each project takes a share by its revenue, its direct cost or the hours worked on it. One entry moves the
 * amount from the pool accounts (credit) to the projects' cost (debit, with the project's cost centre and project), so
 * the company's profit is unchanged and the project report shows the burden. A pool already spread is empty, so running
 * the same period again only takes what was booked since.
 */
class ProjectAllocationService
{
    public function __construct(
        protected AccountingReports $reports,
        protected AccountMap $map,
        protected PostingService $posting,
        protected DocumentNumberService $numbers,
    ) {}

    /**
     * Pool and each project's share, without writing anything.
     *
     * @param  array<int, int>  $accountIds  expense accounts that hold the indirect costs
     * @return array{pool: float, accounts: array, basis_total: float, lines: array<int, array>, warnings: array<int, string>}
     */
    public function preview(array $accountIds, Carbon $from, Carbon $to, string $basis, ?float $amount = null): array
    {
        if (! in_array($basis, ProjectAllocationRun::BASES, true)) {
            throw ValidationException::withMessages(['basis' => 'مفتاح التوزيع غير معروف.']);
        }
        $accounts = Account::whereIn('id', $accountIds)->where('type', 'expense')->where('is_parent', false)->get(['id', 'code', 'name_ar'])->keyBy('id');
        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['account_ids' => 'اختر حساب تكلفة غير مباشرة واحدًا على الأقل.']);
        }

        $totals = $this->reports->totalsBetween($from, $to, $accounts->keys()->all());
        $held = [];
        foreach ($accounts as $id => $account) {
            $net = round((float) ($totals[$id]['debit'] ?? 0) - (float) ($totals[$id]['credit'] ?? 0), 2);
            if ($net > 0.004) {
                $held[$id] = ['id' => $id, 'name' => $account->code.' - '.$account->name_ar, 'net' => $net];
            }
        }
        $available = round(array_sum(array_column($held, 'net')), 2);
        $pool = $amount !== null ? round($amount, 2) : $available;
        $warnings = [];
        if ($available <= 0) {
            throw ValidationException::withMessages(['account_ids' => 'لا رصيد مدين على الحسابات المختارة في هذه الفترة (أو سبق توزيعه).']);
        }
        if ($pool <= 0 || $pool > $available + 0.004) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون بين 0.01 وما تحمله الحسابات ('.number_format($available, 2).').']);
        }

        // The credit side takes each pool account's share of the amount; the last one absorbs rounding.
        $credits = [];
        $left = $pool;
        $keys = array_keys($held);
        foreach ($keys as $i => $id) {
            $share = $i === count($keys) - 1 ? $left : round($pool * $held[$id]['net'] / $available, 2);
            $credits[] = ['account_id' => $id, 'name' => $held[$id]['name'], 'amount' => $share];
            $left = round($left - $share, 2);
        }

        $values = $this->basisValues($basis, $from, $to);
        $total = round(array_sum($values), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['basis' => 'لا قيمة لمفتاح التوزيع على أي مشروع مفتوح في هذه الفترة.']);
        }

        $projects = Project::whereIn('id', array_keys($values))->get(['id', 'code', 'name', 'cost_center_id'])->keyBy('id');
        $lines = [];
        $left = $pool;
        $ids = array_keys($values);
        arsort($values);
        $lastId = array_key_last($values);
        foreach ($values as $projectId => $value) {
            $share = $projectId === $lastId ? $left : round($pool * $value / $total, 2);
            $left = round($left - $share, 2);
            $lines[] = ['project_id' => $projectId, 'code' => $projects[$projectId]->code, 'name' => $projects[$projectId]->name, 'basis_value' => round($value, 2), 'share_pct' => round($value / $total * 100, 4), 'amount' => $share];
        }
        foreach ($projects as $project) {
            if (! $project->cost_center_id) {
                $warnings[] = "المشروع {$project->code} بلا مركز تكلفة: سيُحمَّل بلا بعد تحليلي.";
            }
        }

        return ['pool' => $pool, 'available' => $available, 'accounts' => $credits, 'basis_total' => $total, 'lines' => $lines, 'warnings' => $warnings, 'projects' => count($ids)];
    }

    /** Post the allocation: one entry, one cost row per project. */
    public function post(array $accountIds, Carbon $from, Carbon $to, string $basis, ?float $amount = null, ?string $notes = null): ProjectAllocationRun
    {
        return DB::transaction(function () use ($accountIds, $from, $to, $basis, $amount, $notes) {
            $preview = $this->preview($accountIds, $from, $to, $basis, $amount);
            $number = $this->numbers->next('project_allocation', $to);
            $projects = Project::whereIn('id', array_column($preview['lines'], 'project_id'))->get()->keyBy('id');

            $run = ProjectAllocationRun::create([
                'branch_id' => app(ActiveBranch::class)->id(), 'number' => $number, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'basis' => $basis,
                'pool_account_ids' => array_column($preview['accounts'], 'account_id'), 'pool_amount' => $preview['pool'], 'status' => 'posted', 'notes' => $notes, 'created_by' => auth()->id(),
            ]);

            $costLines = [];
            foreach ($preview['lines'] as $line) {
                if ($line['amount'] <= 0) {
                    continue;
                }
                $project = $projects[$line['project_id']];
                $cost = ProjectCost::create([
                    'project_id' => $project->id, 'category' => 'overhead', 'source' => 'allocation', 'reference' => $number, 'date' => $to->toDateString(),
                    'description' => "تحميل تكاليف غير مباشرة {$number}", 'amount' => $line['amount'], 'created_by' => auth()->id(),
                ]);
                $run->lines()->create(['project_id' => $project->id, 'basis_value' => $line['basis_value'], 'share_pct' => $line['share_pct'], 'amount' => $line['amount'], 'project_cost_id' => $cost->id]);
                $costLines[] = ['role' => 'project_costs', 'debit' => $line['amount'], 'cost_center_id' => $project->cost_center_id, 'project_id' => $project->id, 'description' => "{$project->code} - {$number}"];
            }
            foreach ($preview['accounts'] as $credit) {
                $costLines[] = ['account_id' => $credit['account_id'], 'credit' => $credit['amount'], 'description' => "تحميل على المشاريع {$number}"];
            }

            $entry = $this->posting->post([
                'date' => $to, 'reference' => $number, 'description' => "توزيع تكاليف غير مباشرة على المشاريع {$number} ({$from->toDateString()} → {$to->toDateString()})",
                'source' => $run, 'lines' => $costLines,
            ]);
            $run->update(['journal_entry_id' => $entry->id]);

            return $run->load('lines.project');
        });
    }

    /** Undo a run: the entry is reversed and the cost rows removed (refused when a project it charged is closed). */
    public function reverse(ProjectAllocationRun $run): ProjectAllocationRun
    {
        return DB::transaction(function () use ($run) {
            $run = ProjectAllocationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'posted') {
                throw ValidationException::withMessages(['run' => 'التوزيع معكوس مسبقًا.']);
            }
            $closed = Project::whereIn('id', $run->lines()->pluck('project_id'))->where('status', 'closed')->pluck('code');
            if ($closed->isNotEmpty()) {
                throw ValidationException::withMessages(['run' => 'لا يمكن عكس التوزيع: مشاريع مغلقة ('.$closed->implode('، ').').']);
            }

            if ($run->journalEntry) {
                $this->posting->reverse($run->journalEntry, null, "عكس توزيع التكاليف غير المباشرة {$run->number}");
            }
            ProjectCost::whereIn('id', $run->lines()->pluck('project_cost_id')->filter())->get()->each->delete();
            $run->update(['status' => 'reversed']);

            return $run;
        });
    }

    /**
     * Each open project's value of the basis over the period (projects with none are left out).
     *
     * @return array<int, float>
     */
    protected function basisValues(string $basis, Carbon $from, Carbon $to): array
    {
        $projects = Project::whereNotIn('status', ['closed'])->get(['id', 'cost_center_id']);
        $values = [];

        if ($basis === 'hours') {
            $minutes = DB::table('time_entries as t')->join('work_orders as w', 'w.id', '=', 't.work_order_id')
                ->where('w.company_id', $this->companyId())->whereIn('w.project_id', $projects->pluck('id'))
                ->whereBetween('t.started_at', [$from->toDateString(), $to->toDateString().' 23:59:59'])
                ->groupBy('w.project_id')->selectRaw('w.project_id, SUM(t.minutes) as m')->pluck('m', 'project_id');
            foreach ($minutes as $projectId => $m) {
                $values[(int) $projectId] = round((float) $m / 60, 2);
            }

            return array_filter($values, fn ($v) => $v > 0);
        }

        $roles = $basis === 'revenue' ? ['contract_revenue'] : ['project_costs', 'subcontractor_costs'];
        $accountIds = array_map(fn ($r) => $this->map->id($r), $roles);
        $own = (new ProjectAllocationRun)->getMorphClass();
        foreach ($projects as $project) {
            if (! $project->cost_center_id) {
                continue;
            }
            $net = (float) DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.company_id', $this->companyId())->where('l.cost_center_id', $project->cost_center_id)->whereIn('l.account_id', $accountIds)
                ->whereIn('e.status', ['posted', 'reversed'])
                ->where(fn ($q) => $q->whereNull('e.source_type')->orWhere('e.source_type', '!=', $own))   // earlier allocations are not direct cost
                ->whereBetween('e.date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
                ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as v')->value('v');
            $value = $basis === 'revenue' ? -$net : $net;
            if ($value > 0.004) {
                $values[$project->id] = round($value, 2);
            }
        }

        return $values;
    }

    protected function companyId(): int
    {
        return (int) app(TenantManager::class)->getTenantId();
    }
}

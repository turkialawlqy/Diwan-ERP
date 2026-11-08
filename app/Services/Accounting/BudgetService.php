<?php

namespace App\Services\Accounting;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Binding budgets. With the company setting `budget_enforcement` = warn or block, spending on a budgeted account is
 * compared with the approved budget: planned from the start of the fiscal year to the month of the document against
 * what the ledger already holds plus the new amount. Accounts without a budget line are not governed.
 */
class BudgetService
{
    public const MODES = ['off', 'warn', 'block'];

    public function __construct(protected TenantManager $tenants) {}

    public function mode(?int $companyId = null): string
    {
        $companyId ??= $this->tenants->getTenantId();
        $value = CompanySetting::withoutGlobalScopes()->where('company_id', $companyId)->where('key', 'budget_enforcement')->value('value');

        return in_array($value, self::MODES, true) ? $value : 'off';
    }

    /**
     * Check a new expense of $amount on $accountId at $date.
     *
     * @return string|null a warning to show (mode warn) — null when within budget, not governed, or off; throws in mode block
     */
    /** The analytical dimensions a budget line can be set for: dimension => [budget line column, ledger source]. */
    public const DIMENSIONS = ['cost_center' => 'cost_center_id', 'project' => 'project_id', 'branch' => 'branch_id'];

    /**
     * Net debit (credit for revenue accounts is the caller's concern) the ledger holds for one dimension value on an account
     * between two dates: cost centre and project live on the journal line, the branch on its entry.
     */
    public function dimensionActual(string $dimension, int $dimensionId, int $accountId, $from, $to): float
    {
        $column = self::DIMENSIONS[$dimension];

        return round((float) DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->tenants->getTenantId())->where('l.account_id', $accountId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->when($dimension === 'branch', fn ($q) => $q->where('e.branch_id', $dimensionId), fn ($q) => $q->where('l.'.$column, $dimensionId))
            ->whereBetween('e.date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString().' 23:59:59'])
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as v')->value('v'), 2);
    }

    /**
     * @param  array<string, int|null>  $dimensions  cost_center_id / project_id / branch_id of the spending: a budget line set for
     *                                               that dimension value on the account governs it, otherwise the account-level line does.
     */
    public function check(int $accountId, $date, float $amount, ?string $what = null, array $dimensions = []): ?string
    {
        $mode = $this->mode();
        if ($mode === 'off' || $amount <= 0) {
            return null;
        }

        $day = Carbon::parse($date);
        $companyId = $this->tenants->getTenantId();
        $year = FiscalYear::where('company_id', $companyId)->whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day)->first();
        $budget = $year ? Budget::where('fiscal_year_id', $year->id)->where('status', 'approved')->first() : null;
        if (! $budget) {
            return null;
        }

        $to = (int) $day->format('Ym');
        $scope = null;      // [dimension, id] of a budget line set specifically for this spending
        foreach (self::DIMENSIONS as $dimension => $column) {
            $id = (int) ($dimensions[$column] ?? 0);
            if ($id && BudgetLine::where('budget_id', $budget->id)->where('account_id', $accountId)->where($column, $id)->exists()) {
                $scope = [$dimension, $id, $column];
                break;
            }
        }

        $lines = BudgetLine::where('budget_id', $budget->id)->where('account_id', $accountId)
            ->when($scope, fn ($q) => $q->where($scope[2], $scope[1]), fn ($q) => $q->whereNull('cost_center_id')->whereNull('project_id')->whereNull('branch_id'));
        if (! (clone $lines)->exists()) {
            return null;
        }
        $planned = (float) (clone $lines)->where('period', '<=', $to)->sum('amount');

        $actual = $scope
            ? $this->dimensionActual($scope[0], $scope[1], $accountId, $year->start_date, $day)
            : DB::table('account_balances')->where('company_id', $companyId)->where('account_id', $accountId)
                ->whereBetween('period', [(int) Carbon::parse($year->start_date)->format('Ym'), $to])->selectRaw('COALESCE(SUM(debit - credit), 0) as v')->value('v');
        $after = round((float) $actual + $amount, 2);

        if ($after <= round($planned, 2) + 0.005) {
            return null;
        }

        $what = $scope ? trim(($what ?? '').' — موازنة البعد') : $what;
        $message = sprintf('تجاوز الموازنة%s: المخطط حتى %s %s والمنفَّذ بعد هذا المبلغ %s.', $what ? " ({$what})" : '', $day->format('m/Y'), number_format($planned, 2), number_format($after, 2));
        if ($mode === 'block' && ! auth()->user()?->can('accounting.budgets.override')) {
            throw ValidationException::withMessages(['budget' => $message.' يلزم صلاحية «تجاوز الموازنة».']);
        }

        return $message;
    }
}

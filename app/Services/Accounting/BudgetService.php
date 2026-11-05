<?php

namespace App\Services\Accounting;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
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
    public function check(int $accountId, $date, float $amount, ?string $what = null): ?string
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
        $planned = (float) BudgetLine::where('budget_id', $budget->id)->where('account_id', $accountId)->where('period', '<=', $to)->sum('amount');
        if (! BudgetLine::where('budget_id', $budget->id)->where('account_id', $accountId)->exists()) {
            return null;
        }

        $actual = DB::table('account_balances')->where('company_id', $companyId)->where('account_id', $accountId)
            ->whereBetween('period', [(int) Carbon::parse($year->start_date)->format('Ym'), $to])->selectRaw('COALESCE(SUM(debit - credit), 0) as v')->value('v');
        $after = round((float) $actual + $amount, 2);

        if ($after <= round($planned, 2) + 0.005) {
            return null;
        }

        $message = sprintf('تجاوز الموازنة%s: المخطط حتى %s %s والمنفَّذ بعد هذا المبلغ %s.', $what ? " ({$what})" : '', $day->format('m/Y'), number_format($planned, 2), number_format($after, 2));
        if ($mode === 'block' && ! auth()->user()?->can('accounting.budgets.override')) {
            throw ValidationException::withMessages(['budget' => $message.' يلزم صلاحية «تجاوز الموازنة».']);
        }

        return $message;
    }
}

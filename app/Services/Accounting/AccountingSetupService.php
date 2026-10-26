<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Makes a company "accounting ready": default chart of accounts (new
 * companies), an account mapped to every system role (existing companies get
 * only the missing ones), cash/bank boxes linked to the ledger and an open
 * fiscal year.
 */
class AccountingSetupService
{
    public function __construct(
        protected AccountMap $map,
        protected FiscalCalendar $calendar,
    ) {}

    public function provision(Company $company): void
    {
        DB::transaction(function () use ($company) {
            $hasChart = Account::withoutGlobalScopes()->where('company_id', $company->id)->exists();

            $hasChart ? $this->completeExistingChart($company) : $this->createDefaultChart($company);

            $this->linkCashAndBankBoxes($company);
            $this->calendar->periodFor(now(), $company->id);
            $this->map->flush();
        });
    }

    protected function createDefaultChart(Company $company): void
    {
        $byCode = [];

        foreach (ChartOfAccountsTemplate::rows() as [$code, $parent, $nameAr, $nameEn, $type, $nature, $isParent, $role, $cashFlow]) {
            $account = Account::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'parent_id' => $parent ? $byCode[$parent]->id : null,
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'type' => $type,
                'nature' => $nature,
                'is_parent' => $isParent,
                'balance' => 0,
                'is_active' => true,
                'is_system' => $role !== null,
                'cash_flow_category' => in_array($cashFlow, ['operating', 'investing', 'financing'], true) ? $cashFlow : null,
            ]);

            $byCode[$code] = $account;

            if ($role) {
                $this->map->set($role, $account->id, $company->id);
            }
        }
    }

    /**
     * Existing charts keep their structure; only roles without a valid mapping
     * get an account (reusing a matching legacy account when one exists).
     */
    protected function completeExistingChart(Company $company): void
    {
        $template = collect(ChartOfAccountsTemplate::rows())->keyBy(0);

        foreach (ChartOfAccountsTemplate::rows() as [$code, $parentCode, $nameAr, $nameEn, $type, $nature, $isParent, $role, $cashFlow]) {
            if (! $role || $this->map->find($role, $company->id)) {
                continue;
            }

            $existing = $this->legacyMatch($company->id, $role)
                ?? Account::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('is_parent', false)
                    ->where(fn ($q) => $q->where('name_ar', $nameAr)->orWhere('code', $code))
                    ->where('type', $type)
                    ->first();

            if (! $existing) {
                $parent = $this->parentFor($company->id, $parentCode, $template, $type);
                $existing = Account::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'parent_id' => $parent?->id,
                    'code' => $this->freeCode($company->id, $code),
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'type' => $type,
                    'nature' => $nature,
                    'is_parent' => false,
                    'balance' => 0,
                    'is_active' => true,
                    'is_system' => true,
                    'cash_flow_category' => in_array($cashFlow, ['operating', 'investing', 'financing'], true) ? $cashFlow : null,
                ]);

                if ($parent && ! $parent->is_parent) {
                    $parent->forceFill(['is_parent' => true])->saveQuietly();
                }
            }

            $this->map->set($role, $existing->id, $company->id);
        }
    }

    /**
     * Recognise accounts of the original demo chart by their codes.
     */
    protected function legacyMatch(int $companyId, string $role): ?Account
    {
        $codes = [
            'default_cash' => ['1111', '1101'],
            'default_bank' => ['1112', '1102'],
            'receivables' => ['1121', '112'],
            'inventory' => ['1131'],
            'vat_input' => ['114', '1104'],
            'payables' => ['211', '2101'],
            'vat_output' => ['212', '2102'],
            'salaries_payable' => ['213'],
            'capital' => ['31'],
            'retained_earnings' => ['32'],
            'sales_revenue' => ['41', '4101'],
            'service_revenue' => ['42'],
            'cogs' => ['51', '5101'],
            'salaries_expense' => ['522'],
            'rent_expense' => ['521'],
        ][$role] ?? [];

        if (! $codes) {
            return null;
        }

        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_parent', false)
            ->whereIn('code', $codes)
            ->orderByRaw('LENGTH(code) DESC')
            ->first();
    }

    protected function parentFor(int $companyId, ?string $parentCode, $template, string $type): ?Account
    {
        // Walk up the template hierarchy until an existing account is found.
        while ($parentCode) {
            $parent = Account::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $parentCode)->first();
            if ($parent && $parent->type === $type) {
                return $parent;
            }
            $parentCode = $template[$parentCode][1] ?? null;
        }

        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('parent_id')
            ->where('type', $type)
            ->first();
    }

    protected function freeCode(int $companyId, string $code): string
    {
        $candidate = $code;
        $suffix = 1;

        while (Account::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $candidate)->exists()) {
            $candidate = $code.str_pad((string) $suffix++, 2, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /**
     * Every cash/bank box needs a ledger account; create a sub-account under
     * the cash group for boxes that have none.
     */
    protected function linkCashAndBankBoxes(Company $company): void
    {
        $boxes = BankAccount::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('account_id')->get();

        foreach ($boxes as $box) {
            $role = $box->type === 'cash' ? 'default_cash' : 'default_bank';
            $box->forceFill(['account_id' => $this->map->find($role, $company->id)])->saveQuietly();
        }
    }
}

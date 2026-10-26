<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\HR\EmployeeFinanceService;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Month-end end-of-service provision for every company with employees.
 */
class HrProvision extends Command
{
    protected $signature = 'erp:hr-provision {--date= : Provision date (default: end of last month)}';

    protected $description = 'Post the end-of-service and annual leave provision adjustments for each company';

    public function handle(TenantManager $tenantManager, EmployeeFinanceService $finance): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subMonthNoOverflow()->endOfMonth();

        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenantManager, $finance, $date) {
            try {
                [$difference, $leave] = $tenantManager->runAs($company, fn () => Employee::exists() ? [$finance->accrueProvision($date), $finance->accrueLeaveProvision($date)] : [0, 0]);
                $this->line("{$company->name}: ".number_format($difference, 2).' / '.number_format($leave, 2));
            } catch (\Throwable $e) {
                $this->error("{$company->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}

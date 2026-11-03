<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Console\Command;

class RunWorkOrders extends Command
{
    protected $signature = 'erp:work-orders';

    protected $description = 'Work orders: alert on orders that did not start on time, close forgotten timers';

    public function handle(TenantManager $tenants, WorkOrderService $orders, PlanGuard $plans): int
    {
        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenants, $orders, $plans) {
            $done = $tenants->runAs($company, fn () => $plans->hasModule($company, 'work_orders') ? $orders->tick() : []);
            if (array_sum($done)) {
                $this->line($company->name.': '.json_encode($done));
            }
        });

        return self::SUCCESS;
    }
}

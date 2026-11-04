<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Saas\PlanGuard;
use App\Services\Sectors\WorkshopService;
use App\Services\TenantManager;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Console\Command;

class RunWorkOrders extends Command
{
    protected $signature = 'erp:work-orders';

    protected $description = 'Work orders: alert on orders that did not start on time, close forgotten timers';

    public function handle(TenantManager $tenants, WorkOrderService $orders, PlanGuard $plans): int
    {
        Company::query()->where('status', '!=', 'suspended')->lazyById(100)->each(function (Company $company) use ($tenants, $orders, $plans) {
            try {
                $done = $tenants->runAs($company, fn () => ($plans->hasModule($company, 'work_orders') ? $orders->tick() : [])
                    // Service reminders belong to the workshop, whether or not work orders are on.
                    + ['service_reminders' => $plans->hasModule($company, 'workshop') ? app(WorkshopService::class)->remind() : 0]);
                if (array_sum($done)) {
                    $this->line($company->name.': '.json_encode($done));
                }
            } catch (\Throwable $e) { // one company's bad record must not stop the others
                report($e);
                $this->error($company->name.': '.$e->getMessage());
            }
        });

        return self::SUCCESS;
    }
}

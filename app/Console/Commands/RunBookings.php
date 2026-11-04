<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Bookings\BookingReminders;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class RunBookings extends Command
{
    protected $signature = 'erp:bookings';

    protected $description = 'Bookings: reminders to customers, alerts for unconfirmed bookings, expiring prepaid packages';

    public function handle(TenantManager $tenants, BookingReminders $reminders, PlanGuard $plans): int
    {
        Company::query()->where('status', '!=', 'suspended')->lazyById(100)->each(function (Company $company) use ($tenants, $reminders, $plans) {
            try {
                $done = $tenants->runAs($company, fn () => $plans->hasModule($company, 'bookings') ? $reminders->tick() : []);
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

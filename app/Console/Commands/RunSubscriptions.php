<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class RunSubscriptions extends Command
{
    protected $signature = 'erp:subscriptions';

    protected $description = 'Customer subscriptions: start billing after trials, apply plan changes at renewal, end non-renewing ones, mark unpaid ones, send renewal reminders';

    public function handle(TenantManager $tenants, SubscriptionService $service): int
    {
        Company::query()->where('status', '!=', 'suspended')->lazyById(100)->each(function (Company $company) use ($tenants, $service) {
            try {
                $done = $tenants->runAs($company, fn () => $service->nightly());
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

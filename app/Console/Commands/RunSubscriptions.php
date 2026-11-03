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
        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenants, $service) {
            $done = $tenants->runAs($company, fn () => $service->nightly());
            if (array_sum($done)) {
                $this->line($company->name.': '.json_encode($done));
            }
        });

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Contracts\ContractService;
use App\Services\Saas\PlanGuard;
use App\Services\Sectors\LeaseService;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class RunContracts extends Command
{
    protected $signature = 'erp:contracts';

    protected $description = 'Contracts: activate approved ones, renew or expire ended ones, end expired leases, send expiry and obligation reminders';

    public function handle(TenantManager $tenants, ContractService $service): int
    {
        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenants, $service) {
            $done = $tenants->runAs($company, fn () => $service->nightly()
                + ['leases_ended' => app(PlanGuard::class)->hasModule($company, 'real_estate') ? app(LeaseService::class)->endExpired() : 0]);
            if (array_sum($done)) {
                $this->line($company->name.': '.json_encode($done));
            }
        });

        return self::SUCCESS;
    }
}

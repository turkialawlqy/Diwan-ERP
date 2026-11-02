<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Contracts\ContractService;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class RunContracts extends Command
{
    protected $signature = 'erp:contracts';

    protected $description = 'Contracts: activate approved ones, renew or expire ended ones, send expiry and obligation reminders';

    public function handle(TenantManager $tenants, ContractService $service): int
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

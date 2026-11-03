<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class CheckSupportSla extends Command
{
    protected $signature = 'erp:support-sla';

    protected $description = 'Flag support tickets that missed their first-response or resolution deadline and notify the team';

    public function handle(TenantManager $tenants, SlaService $sla): int
    {
        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenants, $sla) {
            $n = $tenants->runAs($company, fn () => $sla->checkBreaches());
            if ($n) {
                $this->line("{$company->name}: {$n}");
            }
        });

        return self::SUCCESS;
    }
}

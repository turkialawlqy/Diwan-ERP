<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Saas\PlanGuard;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class CheckSupportSla extends Command
{
    protected $signature = 'erp:support-sla';

    protected $description = 'Flag support tickets that missed their first-response or resolution deadline and notify the team';

    public function handle(TenantManager $tenants, SlaService $sla): int
    {
        Company::query()->where('status', '!=', 'suspended')->lazyById(100)->each(function (Company $company) use ($tenants, $sla) {
            try {
                $n = app(PlanGuard::class)->hasModule($company, 'support') ? $tenants->runAs($company, fn () => $sla->checkBreaches()) : 0;
                if ($n) {
                    $this->line("{$company->name}: {$n}");
                }
            } catch (\Throwable $e) { // one company's bad record must not stop the others
                report($e);
                $this->error($company->name.': '.$e->getMessage());
            }
        });

        return self::SUCCESS;
    }
}

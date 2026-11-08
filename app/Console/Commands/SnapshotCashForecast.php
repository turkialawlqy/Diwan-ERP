<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Reports\CashForecastSnapshots;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class SnapshotCashForecast extends Command
{
    protected $signature = 'erp:cash-snapshot';

    protected $description = 'Keep this week\'s 13-week cash forecast for every company, to compare it with the actual cash later';

    public function handle(CashForecastSnapshots $snapshots, TenantManager $tenants): int
    {
        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($snapshots, $tenants) {
            $tenants->runAs($company, fn () => $snapshots->take());
            $this->line("{$company->name}: snapshot taken");
        });

        return self::SUCCESS;
    }
}

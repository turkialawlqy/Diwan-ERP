<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Saas\AlertService;
use App\Services\Saas\SubscriptionReminders;
use App\Services\TenantManager;
use Illuminate\Console\Command;

class SendAlerts extends Command
{
    protected $signature = 'erp:alerts';

    protected $description = 'Send the daily business alerts digest of every company';

    public function handle(TenantManager $tenantManager, AlertService $alerts, SubscriptionReminders $reminders): int
    {
        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenantManager, $alerts, $reminders) {
            try {
                $sent = $tenantManager->runAs($company, fn () => $alerts->run($company) + $reminders->send($company));
                $this->line("{$company->name}: {$sent}");
            } catch (\Throwable $e) {
                $this->error("{$company->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}

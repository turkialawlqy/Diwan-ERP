<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\RecurringJournalRunner;
use App\Services\Sales\RecurringInvoiceRunner;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunAccountingSchedules extends Command
{
    protected $signature = 'erp:accounting-schedules {task : recurring|depreciation} {--month= : Month (Y-m) for depreciation, default previous month}';

    protected $description = 'Generate due recurring journal entries or post monthly depreciation for every company';

    public function handle(RecurringJournalRunner $recurring, DepreciationService $depreciation, TenantManager $tenantManager): int
    {
        if ($this->argument('task') === 'recurring') {
            $this->info('Recurring entries posted: '.$recurring->runDue());
            $this->info('Recurring invoices issued: '.app(RecurringInvoiceRunner::class)->runDue());

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->endOfMonth()
            : now()->subMonthNoOverflow()->endOfMonth();

        Company::query()->where('status', '!=', 'suspended')->each(function (Company $company) use ($tenantManager, $depreciation, $month) {
            $result = $tenantManager->runAs($company, fn () => $depreciation->run($month));
            if ($result['assets']) {
                $this->line("{$company->name}: {$result['assets']} assets, {$result['amount']}");
            }
        });

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\DocumentBackfill;
use App\Services\Accounting\PostingService;
use Illuminate\Console\Command;

class AccountingSetup extends Command
{
    protected $signature = 'erp:accounting {action=provision : provision|rebuild-balances|backfill} {--company= : Only this company id}';

    protected $description = 'Provision chart/mappings/fiscal year for companies, or rebuild aggregated balances from the ledger';

    public function handle(AccountingSetupService $setup, PostingService $posting, DocumentBackfill $backfill): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id));

        $companies->each(function (Company $company) use ($setup, $posting, $backfill) {
            match ($this->argument('action')) {
                'rebuild-balances' => $posting->rebuildBalances($company->id),
                'backfill' => $backfill->run($company, fn ($name, $posted, $failed) => $this->line("  {$name}: {$posted} posted, {$failed} failed")),
                default => $setup->provision($company),
            };
            $this->line("✓ {$company->name}");
        });

        return self::SUCCESS;
    }
}

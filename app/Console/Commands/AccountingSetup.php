<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\DocumentBackfill;
use App\Services\Accounting\PartyBalanceReconciler;
use App\Services\Accounting\PostingService;
use Illuminate\Console\Command;

class AccountingSetup extends Command
{
    protected $signature = 'erp:accounting {action=provision : provision|rebuild-balances|backfill|reconcile-parties} {--company= : Only this company id} {--fix : With reconcile-parties: rewrite the stored balances from the ledger}';

    protected $description = 'Provision chart/mappings/fiscal year for companies, or rebuild aggregated balances from the ledger';

    public function handle(AccountingSetupService $setup, PostingService $posting, DocumentBackfill $backfill): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id));

        $companies->each(function (Company $company) use ($setup, $posting, $backfill) {
            match ($this->argument('action')) {
                'rebuild-balances' => $posting->rebuildBalances($company->id),
                'reconcile-parties' => $this->reconcile($company),
                'backfill' => $backfill->run($company, fn ($name, $posted, $failed) => $this->line("  {$name}: {$posted} posted, {$failed} failed")),
                default => $setup->provision($company),
            };
            $this->line("✓ {$company->name}");
        });

        return self::SUCCESS;
    }

    protected function reconcile(Company $company): void
    {
        $rows = app(PartyBalanceReconciler::class)->differences($company->id, (bool) $this->option('fix'));
        foreach ($rows as $r) {
            $this->line("  {$r['kind']} #{$r['id']} {$r['name']}: stored {$r['stored']} / ledger {$r['ledger']} (diff {$r['difference']})");
        }
        $this->line($rows ? count($rows).($this->option('fix') ? ' corrected' : ' differ (use --fix)') : '  all balances match the ledger');
    }
}

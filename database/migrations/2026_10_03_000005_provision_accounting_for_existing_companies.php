<?php

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\PostingService;
use Illuminate\Database\Migrations\Migration;

/**
 * Give every existing company the accounting roles, a fiscal year, and build
 * the aggregated balances from its existing ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setup = app(AccountingSetupService::class);
        $posting = app(PostingService::class);

        Company::query()->each(function (Company $company) use ($setup, $posting) {
            $setup->provision($company);
            $posting->rebuildBalances($company->id);
        });
    }

    public function down(): void
    {
        // Data provisioning is not reverted.
    }
};

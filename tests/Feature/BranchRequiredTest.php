<?php

namespace Tests\Feature;

use App\Exceptions\BranchRequiredException;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingSetupService;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Concerns\ObservesRowLocks;
use Tests\TestCase;

/**
 * Saving while the top bar shows «all branches» is a refusal the user corrects by choosing a branch:
 * it is not logged as an error, and a transfer asks for the branch before it touches anything.
 */
class BranchRequiredTest extends TestCase
{
    use ObservesRowLocks, RefreshDatabase;

    protected Branch $riyadh;

    protected BankAccount $from;

    protected BankAccount $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->from = BankAccount::create(['name' => 'الصندوق', 'type' => 'cash', 'currency' => 'SAR', 'branch_id' => $this->riyadh->id, 'current_balance' => 1000, 'is_active' => true]);
        $this->to = BankAccount::create(['name' => 'البنك', 'type' => 'bank', 'currency' => 'SAR', 'branch_id' => $this->riyadh->id, 'current_balance' => 0, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function transfer(int|string $branch)
    {
        return $this->withSession([ActiveBranch::SESSION_KEY => $branch])->from(route('finance.transfers.create'))->post(route('finance.transfers.store'), [
            'from_account_id' => $this->from->id, 'to_account_id' => $this->to->id, 'amount' => 100, 'transfer_date' => '2026-06-15',
        ]);
    }

    public function test_the_refusal_under_all_branches_is_shown_but_not_reported(): void
    {
        Exceptions::fake();

        $this->transfer(ActiveBranch::ALL)->assertRedirect(route('finance.transfers.create'))->assertSessionHas('error', (new BranchRequiredException)->getMessage());

        Exceptions::assertNotReported(BranchRequiredException::class);
        $this->assertFalse(app(ExceptionHandler::class)->shouldReport(new BranchRequiredException));
    }

    public function test_a_transfer_asks_for_the_branch_before_it_locks_a_box_or_draws_a_number(): void
    {
        $queries = $this->queriesOf(fn () => $this->transfer(ActiveBranch::ALL)->assertSessionHas('error'));

        $touched = array_filter($queries, fn ($q) => $q['locked'] || preg_match('/^\s*(insert|update|delete)\b/i', $q['sql']) && in_array($q['table'], ['bank_accounts', 'document_sequences', 'journal_entries'], true));
        $this->assertSame([], array_column($touched, 'sql'));
        $this->assertSame([1000.0, 0.0], [(float) $this->from->fresh()->current_balance, (float) $this->to->fresh()->current_balance]);
        $this->assertSame(0, JournalEntry::where('source_type', 'transfer')->count());

        // With a branch chosen the same transfer goes through.
        $this->transfer($this->riyadh->id)->assertSessionHasNoErrors()->assertRedirect(route('finance.transfers.index'));
        $this->assertSame(1, JournalEntry::where('source_type', 'transfer')->count());
    }
}

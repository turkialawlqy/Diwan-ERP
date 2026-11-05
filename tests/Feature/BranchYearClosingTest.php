<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BranchYearClosingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $a;

    protected Branch $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        $this->a = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->b = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_main' => false, 'is_active' => true]);
        $this->sale($this->a, 1000);
        $this->sale($this->b, 400);
        Carbon::setTestNow('2027-01-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function sale(Branch $branch, float $amount, string $status = 'posted'): void
    {
        app(PostingService::class)->post(['date' => '2026-03-05', 'description' => 'بيع', 'branch_id' => $branch->id, 'status' => $status, 'lines' => [['role' => 'default_cash', 'debit' => $amount], ['role' => 'sales_revenue', 'credit' => $amount]]]);
    }

    public function test_each_branch_gets_its_own_closing_entry(): void
    {
        $year = FiscalYear::firstOrFail();
        app(YearEndClosing::class)->close($year);

        $entries = JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->get();
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $entries->pluck('branch_id')->all());
        $this->assertTrue($year->fresh()->isClosed());
    }

    public function test_all_must_pass_blocks_everything_but_passed_mode_closes_the_ready_branch(): void
    {
        $this->sale($this->b, 50, 'draft');
        $year = FiscalYear::firstOrFail();
        $service = app(YearEndClosing::class);

        $this->assertThrows(fn () => $service->close($year), AccountingException::class);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->count());

        $service->close($year, 'passed');
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->count());
        $this->assertSame(['جدة'], $service->report['skipped']);
        $this->assertFalse($year->fresh()->isClosed());

        // Once the draft is gone the remaining branch closes and the year with it.
        JournalEntry::withoutGlobalScopes()->where('status', 'draft')->each(fn ($e) => $e->lines()->delete() && $e->delete());
        $service->close($year->fresh());
        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->count());
        $this->assertTrue($year->fresh()->isClosed());
    }

    public function test_reopening_reverses_every_branch_entry(): void
    {
        $year = FiscalYear::firstOrFail();
        app(YearEndClosing::class)->close($year);
        app(YearEndClosing::class)->reopen($year->fresh());

        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->where('status', 'reversed')->count());
        $this->assertFalse($year->fresh()->isClosed());
    }

    public function test_a_closed_branch_refuses_posting_while_other_branches_still_post(): void
    {
        $this->sale($this->b, 50, 'draft');
        $year = FiscalYear::firstOrFail();
        app(YearEndClosing::class)->close($year, 'passed');
        $this->assertFalse($year->fresh()->isClosed());

        // Riyadh is closed for the year; a late entry for it is refused.
        $this->assertThrows(fn () => $this->sale($this->a, 10), AccountingException::class);
        // Jeddah is still open.
        $this->sale($this->b, 20);
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->where('branch_id', $this->b->id)->where('description', 'بيع')->where('status', 'posted')->where('entry_type', '!=', 'closing')->where('total_debit', 20)->count());
    }

    public function test_close_page_lists_branch_readiness(): void
    {
        $this->sale($this->b, 50, 'draft');
        $year = FiscalYear::firstOrFail();

        $this->get(route('accounting.fiscal-years.close-form', $year->id))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Accounting/FiscalYears/Close')->has('branches', 2));
    }
}

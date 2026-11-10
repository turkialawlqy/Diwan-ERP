<?php

namespace Tests\Feature;

use App\Exceptions\BranchRequiredException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FiscalYearClosing;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchYearClosingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $a;

    protected Branch $b;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
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

    /** An entry of no branch: only work done without a signed-in user makes one. */
    protected function saleWithoutBranch(float $amount): JournalEntry
    {
        auth()->forgetUser();
        try {
            $entry = $this->postSale(['description' => 'بيع بلا فرع'], $amount);
        } finally {
            $this->actingAs($this->owner);
        }
        $this->assertNull($entry->branch_id);

        return $entry;
    }

    /** A sale posted with $data as what post() is given. */
    protected function postSale(array $data = [], float $amount = 10): JournalEntry
    {
        return app(PostingService::class)->post($data + ['date' => '2026-03-05', 'description' => 'بيع', 'lines' => [['role' => 'default_cash', 'debit' => $amount], ['role' => 'sales_revenue', 'credit' => $amount]]]);
    }

    /**
     * The signed-in user's top bar shows 'a', 'b' or 'all'; 'nobody' signs out. The choice is
     * set on the session directly: by this test's clock the plan has lapsed and refuses the POST.
     */
    protected function working(string $view): void
    {
        if ($view === 'nobody') {
            auth()->forgetUser();

            return;
        }
        $this->actingAs($this->owner);
        request()->setLaravelSession(app('session')->driver());
        $this->assertTrue(app(ActiveBranch::class)->switchTo(match ($view) {
            'a' => $this->a->id,
            'b' => $this->b->id,
            'all' => ActiveBranch::ALL,
        }));
    }

    /** Total of the entries of a type and status per branch ('none': no branch). */
    protected function totalsByBranch(string $type, string $status): array
    {
        return JournalEntry::withoutGlobalScopes()->where('entry_type', $type)->where('status', $status)->get()
            ->groupBy(fn ($entry) => $entry->branch_id ?? 'none')->map(fn ($entries) => (float) $entries->sum('total_debit'))->all();
    }

    public static function closingAndReopeningViews(): array
    {
        return [
            'a branch chosen, reopened in it' => ['a', 'a'],
            'a branch chosen, reopened in another' => ['a', 'b'],
            'a branch chosen, reopened under all branches' => ['a', 'all'],
            'all branches, reopened in a branch' => ['all', 'b'],
            'all branches, reopened under all branches' => ['all', 'all'],
            'no signed-in user' => ['nobody', 'nobody'],
        ];
    }

    public static function views(): array
    {
        return ['a branch chosen' => ['a'], 'all branches' => ['all'], 'no signed-in user' => ['nobody']];
    }

    #[DataProvider('closingAndReopeningViews')]
    public function test_the_entries_of_no_branch_close_and_reopen_without_a_branch(string $closedIn, string $reopenedIn): void
    {
        $this->saleWithoutBranch(300);
        $year = FiscalYear::firstOrFail();
        $totals = [$this->a->id => 1000.0, $this->b->id => 400.0, 'none' => 300.0];

        $this->working($closedIn);
        app(YearEndClosing::class)->close($year);

        $this->assertTrue($year->fresh()->isClosed());
        $this->assertEquals($totals, $this->totalsByBranch('closing', 'posted'));
        $this->assertSame(
            JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->whereNull('branch_id')->value('id'),
            FiscalYearClosing::whereNull('branch_id')->value('closing_entry_id'),
        );

        $this->working($reopenedIn);
        app(YearEndClosing::class)->reopen($year->fresh());

        $this->assertFalse($year->fresh()->isClosed());
        $this->assertEquals($totals, $this->totalsByBranch('closing', 'reversed'));
        $this->assertEquals($totals, $this->totalsByBranch('reversal', 'posted'));
    }

    #[DataProvider('views')]
    public function test_the_reversal_of_any_entry_of_no_branch_stays_without_a_branch(string $view): void
    {
        $entry = $this->saleWithoutBranch(300);

        $this->working($view);
        $reversal = app(PostingService::class)->reverse($entry);

        $this->assertNull($reversal->fresh()->branch_id);
        $this->assertSame('reversed', JournalEntry::withoutGlobalScopes()->whereKey($entry->id)->value('status'));
    }

    public function test_a_user_limited_to_one_branch_reverses_an_entry_of_no_branch_without_filing_it_there(): void
    {
        $entry = $this->saleWithoutBranch(300);
        $limited = $this->userWithRole($this->company, 'accountant', ['branch_id' => $this->b->id]);
        $this->assertSame([$this->b->id], $limited->allowedBranchIds());

        $this->actingAs($limited);

        $this->assertNull(app(PostingService::class)->reverse($entry)->fresh()->branch_id);
    }

    public function test_the_reversal_of_an_entry_with_a_branch_follows_it_in_every_view(): void
    {
        $entry = $this->postSale(['branch_id' => $this->b->id]);

        $this->working('a');
        $this->assertSame($this->b->id, app(PostingService::class)->reverse($entry)->branch_id);

        $other = $this->postSale(['branch_id' => $this->b->id]);
        $this->working('all');
        $this->assertSame($this->b->id, app(PostingService::class)->reverse($other)->branch_id);
    }

    public function test_a_branch_not_given_to_post_still_comes_from_the_source_then_the_top_bar(): void
    {
        $inJeddah = (new Warehouse)->forceFill(['id' => 99, 'branch_id' => $this->b->id]);

        $this->working('a');
        $this->assertSame($this->a->id, $this->postSale()->branch_id);
        $this->assertSame($this->a->id, $this->postSale(['branch_id' => null])->branch_id);
        $this->assertSame($this->b->id, $this->postSale(['branch_id' => null, 'source' => $inJeddah])->branch_id);
        $this->assertSame($this->b->id, $this->postSale(['branch_id' => $this->b->id])->branch_id);

        $this->working('all');
        $this->assertThrows(fn () => $this->postSale(['branch_id' => null]), BranchRequiredException::class);
        $this->assertSame($this->b->id, $this->postSale(['branch_id' => null, 'source' => $inJeddah])->branch_id);
    }

    public function test_no_branch_on_purpose_takes_neither_the_source_nor_the_top_bar_but_yields_to_a_given_branch(): void
    {
        $inJeddah = (new Warehouse)->forceFill(['id' => 99, 'branch_id' => $this->b->id]);

        foreach (['a', 'all'] as $view) {
            $this->working($view);
            $this->assertNull($this->postSale(['no_branch' => true])->fresh()->branch_id);
            $this->assertNull($this->postSale(['no_branch' => true, 'branch_id' => null, 'source' => $inJeddah])->fresh()->branch_id);
            $this->assertSame($this->b->id, $this->postSale(['no_branch' => true, 'branch_id' => $this->b->id])->fresh()->branch_id);
        }
    }

    public function test_a_closing_entry_already_filed_under_a_branch_is_reversed_in_that_branch(): void
    {
        $this->saleWithoutBranch(300);
        $year = FiscalYear::firstOrFail();
        app(YearEndClosing::class)->close($year);

        // What closing the year with a branch chosen used to leave in the ledger.
        $filed = JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->whereNull('branch_id')->firstOrFail();
        DB::table('journal_entries')->where('id', $filed->id)->update(['branch_id' => $this->a->id]);

        $this->working('all');
        app(YearEndClosing::class)->reopen($year->fresh());

        $this->assertSame($this->a->id, JournalEntry::withoutGlobalScopes()->where('reversal_of_id', $filed->id)->value('branch_id'));
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

    protected function expense(Branch $branch, float $amount): void
    {
        app(PostingService::class)->post(['date' => '2026-03-06', 'description' => 'مصروف', 'branch_id' => $branch->id, 'lines' => [['role' => 'general_expense', 'debit' => $amount], ['role' => 'default_cash', 'credit' => $amount]]]);
    }

    /** branch id => total of its posted closing entries. */
    protected function postedClosings(): array
    {
        return JournalEntry::withoutGlobalScopes()->where('entry_type', 'closing')->where('status', 'posted')->get()
            ->groupBy('branch_id')->map(fn ($entries) => (float) $entries->sum('total_debit'))->all();
    }

    /** By the company's trial balance for the year: revenue and expense accounts end at zero and retained earnings hold $result. */
    protected function assertLedgerClosedWith(float $result): void
    {
        $year = FiscalYear::firstOrFail();
        $user = auth()->user();
        auth()->forgetUser(); // a signed-in user's reports follow the branch in the top bar
        $rows = collect(app(AccountingReports::class)->trialBalance($year->start_date, $year->end_date)['rows'])->where('is_parent', false);
        $this->actingAs($user);

        foreach ($rows->whereIn('type', ['revenue', 'expense']) as $row) {
            $this->assertEquals([0, 0], [$row['closing_debit'], $row['closing_credit']], "{$row['code']} {$row['name']}");
        }
        $retained = $rows->firstWhere('id', app(AccountMap::class)->id('retained_earnings'));
        $this->assertEquals([0, $result], [$retained['closing_debit'], $retained['closing_credit']]);
    }

    public function test_closing_again_after_reopening_closes_the_real_result_once(): void
    {
        $this->expense($this->a, 200);
        $this->expense($this->b, 50);
        $year = FiscalYear::firstOrFail();
        $service = app(YearEndClosing::class);
        $totals = [$this->a->id => 1000.0, $this->b->id => 400.0];

        $service->close($year);
        $this->assertEquals($totals, $this->postedClosings());
        $this->assertLedgerClosedWith(1150);

        // Twice over: a third closing must not build on the reversals of the first two either.
        foreach ([1, 2] as $round) {
            $service->reopen($year->fresh());
            $this->assertEquals([800, 350], array_column($service->branchStatus($year->fresh()), 'net'), "reopened, round {$round}");

            $service->close($year->fresh());
            $this->assertEquals($totals, $this->postedClosings(), "closed again, round {$round}");
            $this->assertLedgerClosedWith(1150);
        }
    }

    public function test_an_entry_posted_after_reopening_is_closed_with_the_year(): void
    {
        $year = FiscalYear::firstOrFail();
        $service = app(YearEndClosing::class);
        $service->close($year);
        $service->reopen($year->fresh());

        $this->sale($this->a, 150);
        $service->close($year->fresh());

        $this->assertEquals([$this->a->id => 1150.0, $this->b->id => 400.0], $this->postedClosings());
        $this->assertLedgerClosedWith(1550);
    }

    public function test_an_ordinary_reversal_still_counts_in_the_year_being_closed(): void
    {
        $this->sale($this->a, 300);
        app(PostingService::class)->reverse(JournalEntry::withoutGlobalScopes()->latest('id')->firstOrFail());

        app(YearEndClosing::class)->close(FiscalYear::firstOrFail());

        $this->assertEquals([$this->a->id => 1000.0, $this->b->id => 400.0], $this->postedClosings());
        $this->assertLedgerClosedWith(1400);
    }

    public function test_a_year_already_closed_with_a_doubled_entry_is_repaired_by_reopening_and_closing_it(): void
    {
        $year = FiscalYear::firstOrFail();
        $service = app(YearEndClosing::class);
        $service->close($year);
        $service->reopen($year->fresh());

        // What closing again used to post: each branch's result twice over.
        foreach ([[$this->a, 2000], [$this->b, 800]] as [$branch, $amount]) {
            $entry = app(PostingService::class)->post(['date' => $year->end_date, 'description' => 'قيد إقفال مضاعف', 'entry_type' => 'closing', 'source' => $year, 'branch_id' => $branch->id, 'lines' => [['role' => 'sales_revenue', 'debit' => $amount], ['role' => 'retained_earnings', 'credit' => $amount]]]);
            FiscalYearClosing::create(['company_id' => $year->company_id, 'fiscal_year_id' => $year->id, 'branch_id' => $branch->id, 'closing_entry_id' => $entry->id]);
        }
        $year->refresh()->update(['status' => 'closed']);

        $service->reopen($year->fresh());
        $this->assertEquals([1000, 400], array_column($service->branchStatus($year->fresh()), 'net'));

        $service->close($year->fresh());
        $this->assertEquals([$this->a->id => 1000.0, $this->b->id => 400.0], $this->postedClosings());
        $this->assertLedgerClosedWith(1400);
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

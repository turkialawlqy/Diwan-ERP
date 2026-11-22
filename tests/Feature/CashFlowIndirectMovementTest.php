<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\CashFlowBalances;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The indirect cash flow statement takes each account's movement from the monthly totals, less the
 * lines of the opening and year-end closing entries. In every view, date range and state of the
 * year it must equal what it read until now: a query over every line of the period, kept here as
 * the reference.
 */
class CashFlowIndirectMovementTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected Branch $dammam;

    protected ?User $clerk = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['currency' => 'SAR', 'country_code' => 'SA', 'tax_number' => '310122393500003']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->dammam = Branch::create(['name_ar' => 'الدمام', 'code' => 'DMM', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function role(string $role): int
    {
        return app(AccountMap::class)->id($role, $this->company->id);
    }

    protected function entry(string $date, ?Branch $branch, array $lines, string $type = 'manual', string $status = 'posted'): JournalEntry
    {
        return app(PostingService::class)->post(['date' => $date, 'description' => 'قيد', 'branch_id' => $branch?->id, 'entry_type' => $type, 'status' => $status, 'lines' => $lines]);
    }

    /** An entry that belongs to no branch, as work done without a signed-in user makes one (closing and reversing such entries keeps them there). */
    protected function entryWithoutBranch(string $date, array $lines, string $type = 'manual'): JournalEntry
    {
        auth()->forgetUser();
        try {
            $entry = $this->entry($date, null, $lines, $type);
        } finally {
            $this->actingAs($this->owner);
        }
        $this->assertNull($entry->branch_id);

        return $entry;
    }

    /** $user works with this branch chosen in the top bar (or 'all'). */
    protected function viewing(Branch|string $branch, ?User $user = null): void
    {
        $value = $branch instanceof Branch ? $branch->id : $branch;
        $this->actingAs($user ?? $this->owner)->post(route('context.branch'), ['branch_id' => $value]);
        $this->assertSame($value, session(ActiveBranch::SESSION_KEY));
        app(ActiveBranch::class)->flush();
    }

    /** The reference: the statement with its movement read from every line of the period, as it was until now. */
    protected function fromLines(): AccountingReports
    {
        return new class(app(TenantManager::class), app(AccountMap::class), app(CashFlowBalances::class)) extends AccountingReports
        {
            protected function periodMovement(Carbon $from, Carbon $to): array
            {
                $closingIds = DB::table('journal_entries')->where('company_id', $this->companyId())->where('entry_type', 'closing')->pluck('id');

                return DB::table('journal_entry_lines as l')
                    ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                    ->where('l.company_id', $this->companyId())
                    ->whereIn('e.status', self::STATUSES)
                    ->when($this->branchIds(), fn ($q, $ids) => $q->whereIn('e.branch_id', $ids))
                    ->tap(fn ($q) => $this->withinPeriod($q, $from, $to))
                    ->where(fn ($q) => $q->whereNull('e.entry_type')->orWhereNotIn('e.entry_type', ['opening', 'closing']))
                    ->when($closingIds->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('e.reversal_of_id')->orWhereNotIn('e.reversal_of_id', $closingIds)))
                    ->groupBy('l.account_id')
                    ->selectRaw('l.account_id, SUM(l.credit) - SUM(l.debit) as net')
                    ->pluck('net', 'account_id')->map(fn ($v) => (float) $v)->all();
            }
        };
    }

    /** Each account's movement as these reports read it, without the accounts that end at zero. */
    protected function movement(AccountingReports $reports, string $from, string $to): array
    {
        $movement = (fn () => $this->periodMovement(Carbon::parse($from), Carbon::parse($to)))->call($reports);
        $movement = array_filter(array_map(fn ($amount) => round($amount, 2), $movement), fn ($amount) => $amount != 0.0);
        ksort($movement);

        return $movement;
    }

    protected function statement(AccountingReports $reports, string $from, string $to): array
    {
        return json_decode(json_encode($reports->cashFlowIndirect(Carbon::parse($from), Carbon::parse($to))), true);
    }

    /** Whole years and months, a partial month at either end or both, around the closing date, across a year end, inside a month and astride a month end. */
    protected function ranges(): array
    {
        return [['2025-01-01', '2025-12-31'], ['2026-01-01', '2026-06-30'], ['2025-01-01', '2026-06-30'],
            ['2025-02-01', '2025-02-28'], ['2025-01-01', '2025-03-31'], ['2025-04-01', '2025-04-30'], ['2025-12-01', '2025-12-31'],
            ['2025-01-15', '2025-03-31'], ['2025-01-01', '2025-03-10'], ['2025-01-15', '2025-03-10'], ['2025-03-15', '2025-12-30'],
            ['2025-11-15', '2025-12-31'], ['2025-12-15', '2025-12-30'], ['2025-12-31', '2025-12-31'],
            ['2025-12-15', '2026-01-31'], ['2025-12-31', '2026-01-01'],
            ['2025-02-10', '2025-02-10'], ['2025-02-01', '2025-02-27'], ['2025-01-31', '2025-02-01'], ['2025-02-10', '2025-03-05'], ['2026-03-15', '2026-04-02']];
    }

    protected function assertMatchesLines(): void
    {
        foreach ($this->ranges() as [$from, $to]) {
            $lines = $this->fromLines();
            $months = app(AccountingReports::class);
            $this->assertSame($this->movement($lines, $from, $to), $this->movement($months, $from, $to), "movement {$from} .. {$to}");
            $this->assertSame($this->statement($lines, $from, $to), $this->statement($months, $from, $to), "statement {$from} .. {$to}");
        }
    }

    /** All branches, each branch, and a user who may see only two of the three. */
    protected function assertMatchesLinesInEveryView(): void
    {
        foreach (['all', $this->riyadh, $this->jeddah] as $view) {
            $this->viewing($view);
            $this->assertMatchesLines();
        }

        if (! $this->clerk) {
            $this->clerk = $this->userWithRole($this->company, 'sales');
            $this->clerk->forceFill(['branch_id' => $this->riyadh->id])->save();
            $this->clerk->branches()->sync([$this->riyadh->id, $this->dammam->id]);
        }
        $this->viewing('all', $this->clerk->fresh());
        $this->assertMatchesLines();

        $this->viewing('all');
    }

    /** Every kind of entry the statement treats differently, on both sides of month ends, in each branch and in none. */
    protected function seedYear(int $y): void
    {
        $posting = app(PostingService::class);

        // January: opening balances on the first and in mid-month, ordinary entries on the same days and on the last one.
        $this->entry("{$y}-01-01", $this->riyadh, [['role' => 'default_cash', 'debit' => 1000], ['role' => 'opening_balance_equity', 'credit' => 1000]], 'opening');
        $this->entry("{$y}-01-15", $this->jeddah, [['role' => 'inventory', 'debit' => 400], ['role' => 'opening_balance_equity', 'credit' => 400]], 'opening');
        $this->entry("{$y}-01-15", $this->riyadh, [['role' => 'receivables', 'debit' => 115], ['role' => 'sales_revenue', 'credit' => 100], ['role' => 'vat_output', 'credit' => 15]]);
        $this->entry("{$y}-01-31", $this->riyadh, [['role' => 'default_cash', 'debit' => 230], ['role' => 'sales_revenue', 'credit' => 200], ['role' => 'vat_output', 'credit' => 30], ['role' => 'cogs', 'debit' => 120], ['role' => 'inventory', 'credit' => 120]]);
        $this->entry("{$y}-01-31", $this->jeddah, [['role' => 'default_bank', 'debit' => 300], ['role' => 'receivables', 'credit' => 300]]);

        // February: an opening entry of no branch, an expense on credit and the non-cash adjustments.
        $this->entryWithoutBranch("{$y}-02-10", [['role' => 'receivables', 'debit' => 250], ['role' => 'opening_balance_equity', 'credit' => 250]], 'opening');
        $this->entry("{$y}-02-01", $this->riyadh, [['role' => 'general_expense', 'debit' => 90.1], ['role' => 'payables', 'credit' => 90.1]]);
        $this->entry("{$y}-02-10", $this->jeddah, [['role' => 'depreciation_expense', 'debit' => 50], ['role' => 'accumulated_depreciation', 'credit' => 50]]);
        $this->entry("{$y}-02-28", $this->riyadh, [['role' => 'impairment_loss', 'debit' => 20.02], ['role' => 'accumulated_depreciation', 'credit' => 20.02]]);

        // March and April: an entry and its reversal, an opening entry and its reversal one month later (which stays in the movement).
        $posting->reverse($this->entry("{$y}-03-05", $this->riyadh, [['role' => 'default_bank', 'debit' => 600], ['role' => 'sales_revenue', 'credit' => 600]]), "{$y}-03-20");
        $posting->reverse($this->entry("{$y}-03-15", $this->riyadh, [['role' => 'default_cash', 'debit' => 77.7], ['role' => 'opening_balance_equity', 'credit' => 77.7]], 'opening'), "{$y}-04-02");
        $this->entry("{$y}-04-01", $this->riyadh, [['role' => 'general_expense', 'debit' => 60], ['role' => 'end_of_service_provision', 'credit' => 60]]);
        $this->entry("{$y}-04-30", $this->jeddah, [['role' => 'general_expense', 'debit' => 25.25], ['role' => 'leave_provision', 'credit' => 25.25]]);

        // May: the other types of entry the ledger holds, in a branch and in none (entry_type is never null: the column defaults to manual).
        $this->entry("{$y}-05-20", $this->riyadh, [['role' => 'receivables', 'debit' => 500], ['role' => 'sales_revenue', 'credit' => 500]], 'system');
        $this->entry("{$y}-05-25", $this->jeddah, [['role' => 'payables', 'debit' => 10.5], ['role' => 'other_revenue', 'credit' => 10.5]], 'adjustment');
        $this->entry("{$y}-05-31", $this->riyadh, [['role' => 'general_expense', 'debit' => 12.34], ['role' => 'payables', 'credit' => 12.34]], 'recurring');
        $this->entryWithoutBranch("{$y}-05-15", [['role' => 'default_bank', 'debit' => 45.5], ['role' => 'other_revenue', 'credit' => 45.5]], 'system');

        // June: investing and financing, and a gain the statement takes out of the operating section.
        $this->entry("{$y}-06-10", $this->riyadh, [['role' => 'default_bank', 'debit' => 5000], ['role' => 'long_term_loans', 'credit' => 5000]]);
        $this->entry("{$y}-06-15", $this->riyadh, [['role' => 'default_bank', 'debit' => 900], ['role' => 'fixed_assets', 'credit' => 800], ['role' => 'asset_disposal_gain', 'credit' => 100]]);
    }

    /** The last day of the year: a sale in a branch, on the date the closing entries will carry. */
    protected function seedYearEnd(): void
    {
        $this->entry('2025-12-31', $this->jeddah, [['role' => 'default_cash', 'debit' => 33.33], ['role' => 'sales_revenue', 'credit' => 33.33]]);
    }

    /** Entries not in the ledger: neither totals nor lines may count them. */
    protected function seedDrafts(): void
    {
        $this->entry('2026-03-10', $this->jeddah, [['role' => 'default_bank', 'debit' => 70], ['role' => 'sales_revenue', 'credit' => 70]], 'manual', 'draft');
        $this->entry('2026-03-12', $this->riyadh, [['role' => 'default_cash', 'debit' => 33], ['role' => 'opening_balance_equity', 'credit' => 33]], 'opening', 'draft');
    }

    protected function closedYear(): FiscalYear
    {
        return FiscalYear::whereDate('start_date', '2025-01-01')->firstOrFail();
    }

    protected function closingEntries(?string $status = null, bool $withoutBranch = false): int
    {
        return JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('entry_type', 'closing')->when($status, fn ($q) => $q->where('status', $status))
            ->when($withoutBranch, fn ($q) => $q->whereNull('branch_id'))->count();
    }

    public function test_the_movement_equals_the_ledger_lines_in_every_view_and_range(): void
    {
        $this->seedYear(2025);
        $this->seedYearEnd();
        $this->seedYear(2026);
        $this->seedDrafts();

        $this->assertMatchesLinesInEveryView();
    }

    public function test_the_movement_still_equals_the_lines_after_a_year_is_closed_and_reopened(): void
    {
        $this->seedYear(2025);
        $this->seedYearEnd();
        $this->seedYear(2026);
        $this->seedDrafts();
        $closing = app(YearEndClosing::class);

        // The entries of no branch close in no branch whatever the top bar shows: all branches here, one branch below.
        $this->viewing('all');
        $closing->close($this->closedYear());
        $this->assertSame(3, $this->closingEntries('posted')); // Riyadh, Jeddah and the entries of no branch
        $this->assertSame(1, $this->closingEntries('posted', withoutBranch: true));
        $this->assertMatchesLinesInEveryView();

        $this->viewing('all');
        $closing->reopen($this->closedYear());
        $this->assertSame(3, $this->closingEntries('reversed'));
        $this->assertMatchesLinesInEveryView();

        // Closed again: reversed closing entries, their reversals and new closing entries side by side.
        $this->viewing($this->riyadh);
        $closing->close($this->closedYear());
        $this->assertSame(3, $this->closingEntries('posted'));
        $this->assertSame(1, $this->closingEntries('posted', withoutBranch: true));
        $this->assertSame(3, $this->closingEntries('reversed'));
        $this->assertMatchesLinesInEveryView();
    }

    public function test_the_movement_equals_the_lines_for_closing_entries_of_no_branch(): void
    {
        $this->seedYear(2025);
        $this->seedYearEnd();
        $this->seedYear(2026);
        $closing = app(YearEndClosing::class);

        // With a branch chosen, the closing entry of the entries of no branch and its reversal stay without one.
        $this->viewing($this->riyadh);
        $closing->close($this->closedYear());
        $this->assertSame(1, $this->closingEntries('posted', withoutBranch: true));
        $this->assertMatchesLinesInEveryView();

        $this->viewing($this->riyadh);
        $closing->reopen($this->closedYear());
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('entry_type', 'reversal')->whereNull('branch_id')->whereIn('reversal_of_id', JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('entry_type', 'closing')->select('id'))->count());
        $this->assertMatchesLinesInEveryView();
    }

    public function test_the_movement_equals_the_lines_while_only_some_branches_are_closed(): void
    {
        $this->seedYear(2025);
        $this->seedYearEnd();
        $this->seedYear(2026);
        $this->entry('2025-08-10', $this->jeddah, [['role' => 'default_bank', 'debit' => 70], ['role' => 'sales_revenue', 'credit' => 70]], 'manual', 'draft');

        app(YearEndClosing::class)->close($this->closedYear(), 'passed');

        $this->assertSame(2, $this->closingEntries()); // Jeddah has a draft: Riyadh and no-branch only
        $this->assertFalse($this->closedYear()->isClosed());
        $this->assertMatchesLinesInEveryView();
    }

    public function test_an_opening_entry_moved_to_another_branch_goes_with_its_new_branch(): void
    {
        $this->seedYear(2025);
        $this->seedYear(2026);
        $entry = JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('entry_type', 'opening')->whereDate('date', '2025-01-15')->firstOrFail();

        $entry->update(['branch_id' => $this->dammam->id]);
        $this->assertMatchesLinesInEveryView();

        // Into "no branch": only the whole company reads it.
        $entry->update(['branch_id' => null]);
        $this->assertMatchesLinesInEveryView();
    }

    public function test_what_the_movement_leaves_out_and_what_it_keeps(): void
    {
        // Kept: ordinary entries of any type, a reversed entry with its reversal (they cancel out) and the reversal of an opening entry.
        $this->entry('2025-03-10', $this->riyadh, [['role' => 'receivables', 'debit' => 300], ['role' => 'sales_revenue', 'credit' => 300]]);
        $this->entry('2025-04-10', $this->riyadh, [['role' => 'receivables', 'debit' => 40], ['role' => 'other_revenue', 'credit' => 40]], 'system');
        app(PostingService::class)->reverse($this->entry('2025-05-10', $this->riyadh, [['role' => 'payables', 'debit' => 9], ['role' => 'general_expense', 'credit' => 9]]));
        app(PostingService::class)->reverse($this->entry('2025-06-10', $this->riyadh, [['role' => 'inventory', 'debit' => 70], ['role' => 'opening_balance_equity', 'credit' => 70]], 'opening'));

        // Left out: an opening entry. Not in the ledger at all: drafts, of either type.
        $this->entry('2025-01-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 1000], ['role' => 'opening_balance_equity', 'credit' => 1000]], 'opening');
        $this->entry('2025-07-10', $this->riyadh, [['role' => 'default_cash', 'debit' => 5], ['role' => 'sales_revenue', 'credit' => 5]], 'manual', 'draft');
        $this->entry('2025-07-11', $this->riyadh, [['role' => 'default_cash', 'debit' => 6], ['role' => 'opening_balance_equity', 'credit' => 6]], 'opening', 'draft');

        $this->viewing($this->riyadh);
        $expected = [
            $this->role('sales_revenue') => 300.0,
            $this->role('other_revenue') => 40.0,
            $this->role('receivables') => -340.0,
            $this->role('inventory') => 70.0, // the reversal of the opening entry
            $this->role('opening_balance_equity') => -70.0,
        ];
        ksort($expected);
        $reports = app(AccountingReports::class);
        $totals = $reports->totalsBetween(Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31'));
        $this->assertSame(1000.0, $totals[$this->role('default_cash')]['debit']); // the totals do hold the opening entry

        $this->assertSame($expected, $this->movement($reports, '2025-01-01', '2025-12-31'));
        $this->assertSame(340.0, $reports->cashFlowIndirect(Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31'))['indirect']['net_profit']);

        // Closing entries and their reversals are left out too: the same figures while the year is closed and after it is reopened.
        JournalEntry::withoutGlobalScopes()->where('status', 'draft')->each(fn ($e) => $e->lines()->delete() && $e->delete()); // drafts block the closing
        app(YearEndClosing::class)->close($this->closedYear());
        $this->assertSame(1, $this->closingEntries('posted'));
        $this->assertSame($expected, $this->movement($reports, '2025-01-01', '2025-12-31'));

        app(YearEndClosing::class)->reopen($this->closedYear());
        $this->assertSame(1, $this->closingEntries('reversed'));
        $this->assertSame($expected, $this->movement($reports, '2025-01-01', '2025-12-31'));
        $this->assertSame(340.0, $reports->cashFlowIndirect(Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31'))['indirect']['net_profit']);
    }

    public function test_whole_months_read_only_the_lines_of_the_opening_and_closing_entries(): void
    {
        $this->seedYear(2025);
        app(YearEndClosing::class)->close($this->closedYear());
        $this->viewing('all');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AccountingReports::class)->cashFlowIndirect(Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31'));
        $lineQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'journal_entry_lines'));
        DB::disableQueryLog();

        $this->assertCount(2, $lineQueries); // the opening entries' lines, the closing entries' lines
        $this->assertSame([], $lineQueries->reject(fn ($q) => preg_match('/journal_entry_id.? in \(/i', $q))->values()->all());
    }

    public function test_partial_months_read_only_those_months_from_the_period(): void
    {
        $this->seedYear(2025);
        $this->viewing('all');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AccountingReports::class)->cashFlowIndirect(Carbon::parse('2025-01-15'), Carbon::parse('2025-03-10'));
        $scans = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'journal_entry_lines') && ! preg_match('/journal_entry_id.? in \(/i', $q['query']));
        DB::disableQueryLog();

        $this->assertNotEmpty($scans);
        $this->assertEmpty($scans->pluck('bindings')->flatten()->filter(fn ($binding) => is_string($binding) && str_starts_with($binding, '2025-02'))->all());
        // On MySQL, without this the whole company's partial months walk every line of the ledger.
        $this->assertNotEmpty($scans->filter(fn ($q) => str_contains($q['query'], 'JOIN_ORDER(e, l)')));
    }

    public function test_periods_without_an_opening_entry_do_not_read_any_line_of_it(): void
    {
        $this->entry('2025-01-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 1000], ['role' => 'opening_balance_equity', 'credit' => 1000]], 'opening');
        $this->entry('2025-03-10', $this->riyadh, [['role' => 'receivables', 'debit' => 300], ['role' => 'sales_revenue', 'credit' => 300]]);
        $this->viewing('all');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AccountingReports::class)->cashFlowIndirect(Carbon::parse('2025-02-01'), Carbon::parse('2025-03-31'));
        $lineQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'journal_entry_lines'));
        DB::disableQueryLog();

        $this->assertSame([], $lineQueries->values()->all());
    }
}

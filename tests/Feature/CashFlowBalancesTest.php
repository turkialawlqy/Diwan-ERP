<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cash flow statement reads whole months from cash_flow_balances. Whatever the cash
 * accounts are, the branches viewed or the dates chosen, its figures must equal the statement
 * computed from the ledger lines: what it read until now, and still reads for partial months.
 */
class CashFlowBalancesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

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

    /** $user works with this branch chosen in the top bar (or 'all'). */
    protected function viewing(Branch|string $branch, ?User $user = null): void
    {
        $value = $branch instanceof Branch ? $branch->id : $branch;
        $this->actingAs($user ?? $this->owner)->post(route('context.branch'), ['branch_id' => $value]);
        $this->assertSame($value, session(ActiveBranch::SESSION_KEY));
        app(ActiveBranch::class)->flush();
    }

    protected function statement(string $from, string $to): array
    {
        return app(AccountingReports::class)->cashFlow(Carbon::parse($from), Carbon::parse($to));
    }

    /** The statement computed from the ledger lines alone: the line query, for the whole range. */
    protected function statementFromLines(string $from, string $to): array
    {
        $reports = new class(app(TenantManager::class), app(AccountMap::class), app(CashFlowBalances::class)) extends AccountingReports
        {
            protected function cashFlowTotals(Carbon $from, Carbon $to, array $cashIds): Collection
            {
                return $this->cashFlowLines($from, $to, $cashIds);
            }
        };

        return $reports->cashFlow(Carbon::parse($from), Carbon::parse($to));
    }

    /** Whole months, a partial month at either end or both, one day, and ranges with no whole month in them. */
    protected function ranges(): array
    {
        return [['2026-01-01', '2026-06-30'], ['2026-01-01', '2026-03-31'], ['2026-02-01', '2026-02-28'], ['2026-02-01', '2026-02-27'],
            ['2026-01-15', '2026-03-31'], ['2026-01-01', '2026-03-10'], ['2026-01-15', '2026-03-10'], ['2026-02-10', '2026-02-10'],
            ['2026-02-10', '2026-03-05'], ['2026-01-31', '2026-02-01'], ['2025-12-15', '2026-01-31'], ['2026-05-01', '2026-05-31']];
    }

    protected function normal(array $report): array
    {
        return json_decode(json_encode($report), true);
    }

    protected function assertMatchesLines(?array $ranges = null): void
    {
        foreach ($ranges ?? $this->ranges() as [$from, $to]) {
            $this->assertSame($this->normal($this->statementFromLines($from, $to)), $this->normal($this->statement($from, $to)), "{$from} .. {$to}");
        }
    }

    protected function assertMatchesLinesInEveryView(): void
    {
        foreach (['all', $this->riyadh, $this->jeddah] as $view) {
            $this->viewing($view);
            $this->assertMatchesLines();
        }
    }

    /** Every kind of entry the statement treats differently, on both sides of month ends, in each branch and in none. */
    protected function seedLedger(): void
    {
        // January: opening balances, a sale, a sale with its cost in the same entry (cogs and inventory cancel out), a sale in the other branch.
        $this->entry('2026-01-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 1000], ['role' => 'opening_balance_equity', 'credit' => 1000]], 'opening');
        $this->entry('2026-01-15', $this->riyadh, [['role' => 'default_cash', 'debit' => 115], ['role' => 'sales_revenue', 'credit' => 100], ['role' => 'vat_output', 'credit' => 15]]);
        $this->entry('2026-01-31', $this->riyadh, [['role' => 'default_cash', 'debit' => 230], ['role' => 'sales_revenue', 'credit' => 200], ['role' => 'vat_output', 'credit' => 30], ['role' => 'cogs', 'debit' => 120], ['role' => 'inventory', 'credit' => 120]]);
        $this->entry('2026-01-31', $this->jeddah, [['role' => 'default_bank', 'debit' => 300], ['role' => 'receivables', 'credit' => 300]]);

        // February: a pure transfer between cash accounts, one with a fee, and one with unrelated lines (the cash nets to zero).
        $this->entry('2026-02-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 500], ['role' => 'default_bank', 'credit' => 500]]);
        $this->entry('2026-02-10', $this->riyadh, [['role' => 'default_cash', 'debit' => 500], ['role' => 'default_bank', 'credit' => 505], ['role' => 'bank_charges', 'debit' => 5]]);
        $this->entry('2026-02-28', $this->jeddah, [['role' => 'default_cash', 'debit' => 40], ['role' => 'default_bank', 'credit' => 40], ['role' => 'general_expense', 'debit' => 40], ['role' => 'payables', 'credit' => 40]]);

        // March: an entry and its reversal, and a draft (not in the ledger).
        app(PostingService::class)->reverse($this->entry('2026-03-05', $this->riyadh, [['role' => 'default_bank', 'debit' => 600], ['role' => 'sales_revenue', 'credit' => 600]]));
        $this->entry('2026-03-10', $this->jeddah, [['role' => 'default_bank', 'debit' => 70], ['role' => 'sales_revenue', 'credit' => 70]], 'manual', 'draft');

        // April: no cash at all (they count as soon as one of these accounts becomes cash).
        $this->entry('2026-04-01', $this->riyadh, [['role' => 'receivables', 'debit' => 900], ['role' => 'sales_revenue', 'credit' => 900]]);
        $this->entry('2026-04-30', $this->jeddah, [['role' => 'general_expense', 'debit' => 222.22], ['role' => 'inventory', 'credit' => 222.22]]);

        // May: an entry wider than the shapes enumerate, and one without a branch (the whole company only).
        $this->entry('2026-05-15', $this->riyadh, array_merge([['role' => 'default_cash', 'debit' => 120]], $this->wideCredits(10)));
        auth()->forgetUser();
        try {
            $this->entry('2026-05-20', null, [['role' => 'default_bank', 'debit' => 45.5], ['role' => 'other_revenue', 'credit' => 45.5]]);
        } finally {
            $this->actingAs($this->owner);
        }

        // June: investing and financing flows.
        $this->entry('2026-06-01', $this->jeddah, [['role' => 'petty_cash', 'credit' => 33.33], ['role' => 'marketing_expense', 'debit' => 33.33]]);
        $this->entry('2026-06-10', $this->riyadh, [['role' => 'default_bank', 'debit' => 5000], ['role' => 'long_term_loans', 'credit' => 5000]]);
        $this->entry('2026-06-15', $this->riyadh, [['role' => 'default_bank', 'credit' => 999.99], ['role' => 'fixed_assets', 'debit' => 999.99]]);
    }

    /** One credit line on each of twelve accounts: with the cash line, thirteen accounts in one entry. */
    protected function wideCredits(float $amount): array
    {
        $map = app(AccountMap::class);

        return collect(array_keys(AccountMap::ROLES))
            ->filter(fn ($role) => in_array(AccountMap::ROLES[$role][1], ['revenue', 'liability', 'equity'], true) && $map->find($role, $this->company->id))
            ->take(12)->values()
            ->map(fn ($role) => ['role' => $role, 'credit' => $amount])
            ->all();
    }

    /** What the table holds, without its timestamps. */
    protected function storedRows(): array
    {
        return DB::table('cash_flow_balances')->where('company_id', $this->company->id)->get()
            ->map(fn ($r) => implode('|', [$r->branch_id, $r->period, (int) $r->is_opening, $r->shape, $r->account_id, number_format((float) $r->debit, 2, '.', ''), number_format((float) $r->credit, 2, '.', '')]))
            ->sort()->values()->all();
    }

    public function test_a_statement_read_from_the_monthly_totals_equals_the_ledger_lines_in_every_view_and_range(): void
    {
        $this->seedLedger();

        $this->assertMatchesLinesInEveryView();
    }

    public function test_the_figures_are_what_the_definition_says(): void
    {
        $this->entry('2026-01-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 1000], ['role' => 'opening_balance_equity', 'credit' => 1000]], 'opening');
        $this->entry('2026-01-31', $this->riyadh, [['role' => 'default_cash', 'debit' => 230], ['role' => 'sales_revenue', 'credit' => 200], ['role' => 'vat_output', 'credit' => 30], ['role' => 'cogs', 'debit' => 120], ['role' => 'inventory', 'credit' => 120]]);
        $this->entry('2026-02-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 500], ['role' => 'default_bank', 'credit' => 500]]);
        $this->entry('2026-02-10', $this->riyadh, [['role' => 'default_cash', 'debit' => 500], ['role' => 'default_bank', 'credit' => 505], ['role' => 'bank_charges', 'debit' => 5]]);
        $this->entry('2026-02-28', $this->riyadh, [['role' => 'default_cash', 'debit' => 40], ['role' => 'default_bank', 'credit' => 40], ['role' => 'general_expense', 'debit' => 40], ['role' => 'payables', 'credit' => 40]]);
        $this->entry('2026-03-05', $this->riyadh, [['role' => 'receivables', 'debit' => 90], ['role' => 'sales_revenue', 'credit' => 90]]);

        $report = $this->statement('2026-01-01', '2026-03-31');

        $operating = collect($report['sections']['operating']['rows'])->pluck('amount', 'account_id');
        $this->assertEquals(200.0, $operating[$this->role('sales_revenue')]);
        $this->assertEquals(30.0, $operating[$this->role('vat_output')]);
        $this->assertEquals(-120.0, $operating[$this->role('cogs')]);
        $this->assertEquals(120.0, $operating[$this->role('inventory')]);
        $this->assertEquals(-5.0, $operating[$this->role('bank_charges')]);
        $this->assertSame(5, $operating->count()); // no general expense (the cash nets to zero), no receivables (no cash)
        $this->assertSame(225.0, $report['net_change']);
        $this->assertSame(1000.0, $report['opening_cash']);
        $this->assertSame(1225.0, $report['closing_cash']);
    }

    public function test_whole_months_do_not_read_the_ledger_lines(): void
    {
        $this->seedLedger();
        $this->viewing('all');

        DB::enableQueryLog();
        $this->statement('2026-01-01', '2026-03-31');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertEmpty($queries->filter(fn ($q) => str_contains($q, 'journal_entry_lines'))->values()->all());
    }

    public function test_partial_months_read_only_those_months_from_the_lines(): void
    {
        $this->seedLedger();
        $this->viewing('all');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->statement('2026-01-15', '2026-03-10');
        $lineQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'journal_entry_lines'));
        DB::disableQueryLog();

        $this->assertNotEmpty($lineQueries);
        $this->assertEmpty($lineQueries->pluck('bindings')->flatten()->filter(fn ($binding) => is_string($binding) && str_starts_with($binding, '2026-02'))->all());
    }

    public function test_a_cash_account_added_or_reclassified_applies_to_past_entries_at_once(): void
    {
        $this->seedLedger();
        $this->viewing('all');
        $this->assertMatchesLines();
        $original = $this->normal($this->statement('2026-01-01', '2026-06-30'));

        // A bank box on an account that has only had non-cash entries so far: April's sale becomes a cash inflow.
        $box = BankAccount::create(['name' => 'صندوق جديد', 'type' => 'bank', 'currency' => 'SAR', 'account_id' => $this->role('receivables'), 'opening_balance' => 0, 'current_balance' => 0, 'branch_id' => $this->riyadh->id, 'is_active' => true]);
        $this->assertMatchesLines();
        $withBox = $this->normal($this->statement('2026-01-01', '2026-06-30'));
        $this->assertNotSame($original, $withBox);

        // An account reclassified as cash.
        Account::whereKey($this->role('inventory'))->update(['cash_flow_category' => 'cash']);
        $this->assertMatchesLines();
        $withCategory = $this->normal($this->statement('2026-01-01', '2026-06-30'));
        $this->assertNotSame($withBox, $withCategory);

        // An account map role pointed at another account.
        $pettyCash = $this->role('petty_cash');
        app(AccountMap::class)->set('petty_cash', $this->role('general_expense'), $this->company->id);
        $this->assertMatchesLines();
        $withRole = $this->normal($this->statement('2026-01-01', '2026-06-30'));
        $this->assertNotSame($withCategory, $withRole);

        // Taking them back out brings the original statement back.
        $box->delete();
        Account::whereKey($this->role('inventory'))->update(['cash_flow_category' => null]);
        app(AccountMap::class)->set('petty_cash', $pettyCash, $this->company->id);
        $this->assertMatchesLines();
        $this->assertSame($original, $this->normal($this->statement('2026-01-01', '2026-06-30')));
    }

    public function test_a_combination_of_accounts_that_nets_to_zero_is_left_out_whatever_the_cash_accounts_are(): void
    {
        $this->seedLedger();
        $this->viewing('all');

        // Cost of sales and inventory as the only cash accounts: in the cash sale they net to zero.
        app(AccountMap::class)->set('default_cash', $this->role('cogs'), $this->company->id);
        app(AccountMap::class)->set('default_bank', $this->role('inventory'), $this->company->id);
        app(AccountMap::class)->set('petty_cash', null, $this->company->id);
        $this->assertSame([$this->role('cogs'), $this->role('inventory')], app(AccountingReports::class)->cashAccountIds());

        $this->assertMatchesLines();
        $this->assertEmpty(collect($this->statement('2026-01-31', '2026-01-31')['sections'])->flatMap(fn ($s) => $s['rows'])->all());
    }

    public function test_each_branch_counts_its_own_boxes_as_cash(): void
    {
        $this->seedLedger();

        // A box of Jeddah's on an account the Riyadh branch posts to as well.
        BankAccount::create(['name' => 'صندوق جدة', 'type' => 'bank', 'currency' => 'SAR', 'account_id' => $this->role('receivables'), 'opening_balance' => 0, 'current_balance' => 0, 'branch_id' => $this->jeddah->id, 'is_active' => true]);

        $this->viewing('all');
        $this->assertMatchesLines();
        $all = $this->normal($this->statement('2026-04-01', '2026-04-30'));

        $this->viewing($this->riyadh);
        $this->assertMatchesLines();
        $riyadh = $this->normal($this->statement('2026-04-01', '2026-04-30'));

        $this->viewing($this->jeddah);
        $this->assertMatchesLines();

        // Riyadh's April sale on credit is a cash inflow only for the viewer who counts that box as cash.
        $this->assertNotEmpty(collect($all['sections'])->flatMap(fn ($s) => $s['rows'])->all());
        $this->assertEmpty(collect($riyadh['sections'])->flatMap(fn ($s) => $s['rows'])->all());
    }

    public function test_the_indirect_statement_starts_from_the_same_figures(): void
    {
        $this->seedLedger();
        $this->viewing('all');

        $reports = app(AccountingReports::class);
        $indirect = $reports->cashFlowIndirect(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));
        $direct = $this->normal($this->statementFromLines('2026-01-01', '2026-06-30'));

        foreach (['sections', 'net_change', 'opening_cash', 'closing_cash'] as $key) {
            $this->assertSame($direct[$key], $this->normal([$key => $indirect[$key]])[$key]);
        }
    }

    public function test_a_ledger_the_table_does_not_cover_falls_back_to_the_lines(): void
    {
        $this->seedLedger();
        $this->viewing('all');
        $expected = $this->normal($this->statementFromLines('2026-01-01', '2026-06-30'));

        // A month missing, as in data loaded outside the posting service.
        DB::table('cash_flow_balances')->where('company_id', $this->company->id)->where('period', 202603)->delete();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame($expected, $this->normal($this->statement('2026-01-01', '2026-06-30')));
        $this->assertNotEmpty(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'journal_entry_lines')));
        DB::disableQueryLog();

        // Nothing at all.
        DB::table('cash_flow_balances')->where('company_id', $this->company->id)->delete();
        $this->assertSame($expected, $this->normal($this->statement('2026-01-01', '2026-06-30')));
    }

    public function test_the_rebuild_writes_what_posting_wrote(): void
    {
        $this->seedLedger();
        $written = $this->storedRows();
        $this->assertNotEmpty($written);

        app(PostingService::class)->rebuildBalances($this->company->id);

        $this->assertSame($written, $this->storedRows());
    }

    public function test_the_migration_fills_the_totals_from_the_existing_ledger(): void
    {
        $this->seedLedger();
        $written = $this->storedRows();
        DB::table('cash_flow_balances')->where('company_id', $this->company->id)->delete();

        (require database_path('migrations/2026_11_22_000003_create_cash_flow_balances.php'))->up();

        $this->assertSame($written, $this->storedRows());
    }

    public function test_the_closing_of_the_entries_of_no_branch_and_its_reversal_stay_under_no_branch(): void
    {
        $this->entry('2026-03-10', $this->riyadh, [['role' => 'default_cash', 'debit' => 1000], ['role' => 'sales_revenue', 'credit' => 1000]]);
        $this->entry('2026-04-10', $this->jeddah, [['role' => 'general_expense', 'debit' => 300], ['role' => 'default_bank', 'credit' => 300]]);
        auth()->forgetUser(); // work without a signed-in user leaves its entry without a branch
        try {
            $this->entry('2026-05-20', null, [['role' => 'default_bank', 'debit' => 45.5], ['role' => 'other_revenue', 'credit' => 45.5]]);
        } finally {
            $this->actingAs($this->owner);
        }
        $withoutBranch = fn (string $type) => JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('entry_type', $type)->whereNull('branch_id')->count();
        $rebuilt = function () {
            $written = $this->storedRows();
            app(PostingService::class)->rebuildBalances($this->company->id);

            return [$written, $this->storedRows()];
        };

        $this->company->forceFill(['trial_ends_at' => '2030-01-01'])->save(); // still subscribed after the year ends
        Carbon::setTestNow('2027-01-10 09:00:00');
        $year = FiscalYear::whereDate('start_date', '2026-01-01')->firstOrFail();

        // Closed and reopened with a branch chosen: the entry of no branch is closed, and reopened, in no branch.
        $this->viewing($this->riyadh);
        app(YearEndClosing::class)->close($year);
        $this->assertSame(1, $withoutBranch('closing'));
        [$written, $rebuild] = $rebuilt();
        $this->assertSame($written, $rebuild);
        $this->assertMatchesLinesInEveryView();

        app(YearEndClosing::class)->reopen($year->fresh());
        $this->assertSame(1, $withoutBranch('reversal'));
        [$written, $rebuild] = $rebuilt();
        $this->assertSame($written, $rebuild);
        $this->assertMatchesLinesInEveryView();
    }

    public function test_a_draft_counts_only_once_posted(): void
    {
        $draft = $this->entry('2026-03-10', $this->riyadh, [['role' => 'default_cash', 'debit' => 70], ['role' => 'sales_revenue', 'credit' => 70]], 'manual', 'draft');
        $this->assertSame([], $this->storedRows());

        app(PostingService::class)->postDraft($draft);

        $this->assertNotSame([], $this->storedRows());
        $this->viewing('all');
        $this->assertMatchesLines();
        $this->assertSame(70.0, $this->statement('2026-03-01', '2026-03-31')['net_change']);
    }

    public function test_an_entry_moved_to_another_branch_takes_its_amounts_along(): void
    {
        $this->seedLedger();
        $entry = JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->whereDate('date', '2026-06-15')->firstOrFail();

        $entry->update(['branch_id' => $this->jeddah->id]);
        $this->assertMatchesLinesInEveryView();

        // Into "no branch" and back: the bucket only the whole company reads.
        $entry->update(['branch_id' => null]);
        $this->assertMatchesLinesInEveryView();
        $entry->update(['branch_id' => $this->riyadh->id]);
        $this->assertMatchesLinesInEveryView();

        // What a rebuild would write, nothing more (no empty rows left behind).
        $moved = $this->storedRows();
        app(PostingService::class)->rebuildBalances($this->company->id);
        $this->assertSame($this->storedRows(), $moved);
    }

    public function test_entries_with_the_same_accounts_share_their_rows_and_wider_ones_stay_apart(): void
    {
        $this->entry('2026-03-01', $this->riyadh, [['role' => 'default_cash', 'debit' => 10], ['role' => 'sales_revenue', 'credit' => 10]]);
        $this->entry('2026-03-02', $this->riyadh, [['role' => 'sales_revenue', 'credit' => 20], ['role' => 'default_cash', 'debit' => 20]]);
        $this->entry('2026-03-03', $this->riyadh, array_merge([['role' => 'default_cash', 'debit' => 120]], $this->wideCredits(10)));

        $shapes = DB::table('cash_flow_balances')->where('company_id', $this->company->id)->pluck('shape')->unique()->sort()->values();
        $this->assertSame(['#'.JournalEntry::withoutGlobalScopes()->whereDate('date', '2026-03-03')->value('id'), min($this->role('default_cash'), $this->role('sales_revenue')).','.max($this->role('default_cash'), $this->role('sales_revenue'))], $shapes->all());
        $this->assertEquals(30, DB::table('cash_flow_balances')->where('company_id', $this->company->id)->where('account_id', $this->role('default_cash'))->where('shape', 'not like', '#%')->sum('debit'));
    }
}

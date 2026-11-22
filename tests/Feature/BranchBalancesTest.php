<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A report narrowed to some branches reads whole months from branch_account_balances. Those
 * totals must equal the ledger lines, branch by branch and month by month, however an entry
 * reached the ledger. The expected figures are added up here in PHP from the raw lines.
 */
class BranchBalancesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected Branch $dammam;

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

    protected function entry(?Branch $branch, string $date, float $amount, string $status = 'posted'): JournalEntry
    {
        return app(PostingService::class)->post(['date' => $date, 'description' => 'قيد', 'branch_id' => $branch?->id, 'status' => $status,
            'lines' => [['role' => 'default_cash', 'debit' => $amount], ['role' => 'sales_revenue', 'credit' => $amount]]]);
    }

    /** An entry that belongs to no branch, as work done without a signed-in user makes one (closing and reversing such entries keeps them there). */
    protected function entryWithoutBranch(string $date, float $amount): JournalEntry
    {
        auth()->forgetUser();
        try {
            $entry = $this->entry(null, $date, $amount);
        } finally {
            $this->actingAs($this->owner);
        }
        $this->assertNull($entry->branch_id);

        return $entry;
    }

    /** Both sides of a month's boundary, in every branch and in none. */
    protected function seedLedger(): void
    {
        foreach ([
            [$this->riyadh, '2026-01-01', 100], [$this->riyadh, '2026-01-31', 50.25], [$this->jeddah, '2026-01-31', 70],
            [$this->riyadh, '2026-02-15', 30.10], [$this->riyadh, '2026-02-15', 30.10], [$this->riyadh, '2026-02-15', 30.10], [$this->dammam, '2026-02-28', 40],
            [$this->jeddah, '2026-03-01', 90.90], [$this->riyadh, '2026-03-10', 11.11], [$this->riyadh, '2026-03-31', 22.22],
            [$this->dammam, '2026-04-01', 33.33], [$this->jeddah, '2026-04-20', 44.44], [$this->riyadh, '2026-04-10', 7.77],
            [$this->riyadh, '2026-05-02', 55.55], [$this->jeddah, '2026-05-31', 66.66],
        ] as [$branch, $date, $amount]) {
            $this->entry($branch, $date, $amount);
        }
        $this->entryWithoutBranch('2026-02-20', 500);
        $this->entryWithoutBranch('2026-04-30', 600);
    }

    /** What the table holds: "branch|account|period" => [debit, credit]. */
    protected function monthlyTotals(): array
    {
        $rows = DB::table('branch_account_balances')->where('company_id', $this->company->id)->get()
            ->mapWithKeys(fn ($r) => [$r->branch_id.'|'.$r->account_id.'|'.$r->period => [round((float) $r->debit, 2), round((float) $r->credit, 2)]])->all();
        ksort($rows);

        return $rows;
    }

    /** The same figures added up from the ledger lines. */
    protected function monthlyLines(): array
    {
        $rows = [];
        foreach ($this->postedLines() as $line) {
            $key = ((int) $line->branch_id).'|'.$line->account_id.'|'.Carbon::parse($line->date)->format('Ym');
            $rows[$key] = [($rows[$key][0] ?? 0) + (float) $line->debit, ($rows[$key][1] ?? 0) + (float) $line->credit];
        }
        ksort($rows);

        return array_map(fn ($row) => [round($row[0], 2), round($row[1], 2)], $rows);
    }

    protected function postedLines()
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->company->id)->whereIn('e.status', ['posted', 'reversed'])
            ->get(['e.branch_id', 'l.account_id', 'l.date', 'l.debit', 'l.credit']);
    }

    /** What totalsBetween() must answer, added up from the lines: account => [debit, credit]. Null branches: the whole company. */
    protected function totalsFromLines(?string $from, string $to, ?array $branchIds): array
    {
        $rows = [];
        foreach ($this->postedLines() as $line) {
            $date = Carbon::parse($line->date)->toDateString();
            if (($from && $date < $from) || $date > $to || ($branchIds !== null && ! in_array((int) $line->branch_id, $branchIds, true))) {
                continue;
            }
            $rows[$line->account_id] = [($rows[$line->account_id][0] ?? 0) + (float) $line->debit, ($rows[$line->account_id][1] ?? 0) + (float) $line->credit];
        }
        ksort($rows);

        return array_map(fn ($row) => [round($row[0], 2), round($row[1], 2)], $rows);
    }

    protected function reported(?string $from, string $to): array
    {
        $rows = app(AccountingReports::class)->totalsBetween($from ? Carbon::parse($from) : null, Carbon::parse($to));
        ksort($rows);

        return array_map(fn ($row) => [round($row['debit'], 2), round($row['credit'], 2)], $rows);
    }

    /** $user works with this branch chosen in the top bar (or 'all'). */
    protected function viewing(Branch|string $branch, ?User $user = null): void
    {
        $value = $branch instanceof Branch ? $branch->id : $branch;
        $this->actingAs($user ?? $this->owner)->post(route('context.branch'), ['branch_id' => $value]);
        $this->assertSame($value, session(ActiveBranch::SESSION_KEY));
        app(ActiveBranch::class)->flush();
    }

    /** Whole months, a partial month at either end or both, and ranges with no whole month in them. */
    protected function ranges(): array
    {
        return [[null, '2026-06-30'], [null, '2026-03-31'], [null, '2026-03-20'], ['2026-02-01', '2026-04-30'], ['2026-02-15', '2026-04-30'],
            ['2026-02-01', '2026-04-10'], ['2026-02-15', '2026-04-10'], ['2026-03-05', '2026-03-20'], ['2026-03-20', '2026-04-05'], ['2026-01-31', '2026-02-01'], ['2026-06-01', '2026-06-30']];
    }

    protected function assertSummaryMatchesLedger(): void
    {
        $this->assertSame($this->monthlyLines(), $this->monthlyTotals());
    }

    public function test_the_monthly_totals_equal_the_ledger_lines_per_branch_account_and_month(): void
    {
        $this->seedLedger();

        $this->assertSummaryMatchesLedger();
        $this->assertNotEmpty($this->monthlyTotals());
    }

    public function test_the_branches_add_up_to_the_company_balances(): void
    {
        $this->seedLedger();

        $branches = [];
        foreach ($this->monthlyTotals() as $key => $row) {
            [, $account, $period] = explode('|', $key);
            $branches[$account.'|'.$period] = [($branches[$account.'|'.$period][0] ?? 0) + $row[0], ($branches[$account.'|'.$period][1] ?? 0) + $row[1]];
        }
        $company = DB::table('account_balances')->where('company_id', $this->company->id)->get()
            ->mapWithKeys(fn ($r) => [$r->account_id.'|'.$r->period => [round((float) $r->debit, 2), round((float) $r->credit, 2)]])->all();
        ksort($branches);
        ksort($company);

        $this->assertSame($company, array_map(fn ($row) => [round($row[0], 2), round($row[1], 2)], $branches));
    }

    public function test_every_range_answers_as_the_ledger_lines_for_one_branch(): void
    {
        $this->seedLedger();

        foreach ([$this->riyadh, $this->jeddah, $this->dammam] as $branch) {
            $this->viewing($branch);
            foreach ($this->ranges() as [$from, $to]) {
                $this->assertSame($this->totalsFromLines($from, $to, [$branch->id]), $this->reported($from, $to), "{$branch->code} {$from}..{$to}");
            }
        }
    }

    public function test_every_range_answers_as_the_ledger_lines_for_several_branches(): void
    {
        $this->seedLedger();
        $clerk = $this->userWithRole($this->company, 'sales');
        $clerk->forceFill(['branch_id' => $this->riyadh->id])->save();
        $clerk->branches()->sync([$this->riyadh->id, $this->dammam->id]);

        $this->viewing('all', $clerk->fresh());

        foreach ($this->ranges() as [$from, $to]) {
            $this->assertSame($this->totalsFromLines($from, $to, [$this->riyadh->id, $this->dammam->id]), $this->reported($from, $to), "{$from}..{$to}");
        }
    }

    public function test_entries_without_a_branch_stay_out_of_every_branch_and_in_the_company(): void
    {
        $this->seedLedger();

        $this->viewing($this->riyadh);
        $riyadh = $this->reported(null, '2026-06-30');
        $this->viewing('all');
        $company = $this->reported(null, '2026-06-30');

        $this->assertSame($this->totalsFromLines(null, '2026-06-30', [$this->riyadh->id]), $riyadh);
        $this->assertSame($this->totalsFromLines(null, '2026-06-30', null), $company);
        $cash = array_key_first($company);
        $this->assertSame(1100.0, round($company[$cash][0] - array_sum(array_column(array_map(fn ($b) => $this->totalsFromLines(null, '2026-06-30', [$b->id])[$cash] ?? [0, 0], [$this->riyadh, $this->jeddah, $this->dammam]), 0)), 2));
    }

    public function test_whole_months_come_from_the_monthly_totals_and_only_edge_months_from_the_lines(): void
    {
        $this->seedLedger();
        $this->viewing($this->riyadh);
        $reports = app(AccountingReports::class);
        $linesRead = function (string $from, string $to) use ($reports) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $reports->totalsBetween(Carbon::parse($from), Carbon::parse($to));
            $log = collect(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertTrue($log->contains(fn ($q) => str_contains($q['query'], 'branch_account_balances')));

            return $log->filter(fn ($q) => str_contains($q['query'], 'journal_entry_lines'))->values();
        };

        $this->assertCount(0, $linesRead('2026-02-01', '2026-04-30'));

        $tail = $linesRead('2026-02-01', '2026-04-10');
        $this->assertCount(1, $tail);
        $this->assertContains('2026-04-01', $tail[0]['bindings']);

        $head = $linesRead('2026-02-15', '2026-04-30');
        $this->assertCount(1, $head);
        $this->assertContains('2026-02-15', $head[0]['bindings']);
    }

    public function test_a_draft_counts_only_once_it_is_posted_in_the_branch_it_ends_up_in(): void
    {
        $draft = $this->entry($this->riyadh, '2026-03-10', 80, 'draft');
        $this->assertSame([], $this->monthlyTotals());

        $draft = JournalEntry::withoutGlobalScopes()->findOrFail($draft->id);
        $draft->update(['branch_id' => $this->jeddah->id]);
        $this->assertSame([], $this->monthlyTotals());

        app(PostingService::class)->postDraft($draft->fresh());

        $this->assertSummaryMatchesLedger();
        $this->assertSame([$this->jeddah->id], array_values(array_unique(array_map(fn ($k) => (int) explode('|', $k)[0], array_keys($this->monthlyTotals())))));
    }

    public function test_a_reversal_lands_in_the_branch_of_its_entry_and_the_entry_stays(): void
    {
        $entry = $this->entry($this->jeddah, '2026-03-10', 250);
        $this->entry($this->riyadh, '2026-03-10', 10);

        $reversal = app(PostingService::class)->reverse($entry);

        $this->assertSame($this->jeddah->id, $reversal->branch_id);
        $this->assertSummaryMatchesLedger();
        $this->viewing($this->jeddah);
        foreach ($this->reported('2026-03-01', '2026-03-31') as [$debit, $credit]) {
            $this->assertSame(250.0, $debit);
            $this->assertSame(250.0, $credit);
        }
    }

    public function test_an_entry_moved_to_another_branch_takes_its_amounts_along(): void
    {
        $this->entry($this->riyadh, '2026-03-10', 100);
        $moved = JournalEntry::withoutGlobalScopes()->findOrFail($this->entry($this->riyadh, '2026-03-11', 250)->id);
        $alone = JournalEntry::withoutGlobalScopes()->findOrFail($this->entry($this->dammam, '2026-04-02', 30)->id);

        $moved->update(['branch_id' => $this->jeddah->id]);
        $this->assertSummaryMatchesLedger();

        // Into and out of "no branch": the bucket every other branch's report leaves out.
        $moved->update(['branch_id' => null]);
        $this->assertSummaryMatchesLedger();
        $moved->update(['branch_id' => $this->jeddah->id]);
        $this->assertSummaryMatchesLedger();

        // The last entry of a branch's month leaves no empty row behind: the table is what a rebuild would write.
        $alone->update(['branch_id' => $this->riyadh->id]);
        $this->assertSummaryMatchesLedger();
        $this->assertEmpty(array_filter(array_keys($this->monthlyTotals()), fn ($k) => (int) explode('|', $k)[0] === $this->dammam->id));

        $this->viewing($this->jeddah);
        $this->assertSame($this->totalsFromLines(null, '2026-06-30', [$this->jeddah->id]), $this->reported(null, '2026-06-30'));
    }

    public function test_the_rebuild_corrects_a_ledger_changed_behind_the_posting_service(): void
    {
        $this->seedLedger();
        $entry = $this->entry($this->riyadh, '2026-03-10', 999);

        // In raw SQL, as a data repair would: nothing tells the totals.
        DB::table('journal_entries')->where('id', $entry->id)->update(['branch_id' => $this->dammam->id]);
        DB::table('branch_account_balances')->where('company_id', $this->company->id)->where('branch_id', $this->jeddah->id)->delete();
        $this->assertNotSame($this->monthlyLines(), $this->monthlyTotals());

        app(PostingService::class)->rebuildBalances($this->company->id);

        $this->assertSummaryMatchesLedger();
    }

    public function test_the_migration_fills_the_totals_from_the_existing_ledger(): void
    {
        $this->seedLedger();
        $this->entry($this->riyadh, '2026-03-10', 80, 'draft');
        DB::table('branch_account_balances')->where('company_id', $this->company->id)->delete();

        (require database_path('migrations/2026_11_22_000001_create_branch_account_balances.php'))->up();

        $this->assertSummaryMatchesLedger();
    }

    public function test_profit_reports_of_a_branch_leave_out_the_closing_entries_and_their_reversals(): void
    {
        $this->entry($this->riyadh, '2026-03-10', 1000);
        $this->entry($this->jeddah, '2026-03-11', 400);
        $income = fn () => app(AccountingReports::class)->incomeStatement(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))['total_revenue'];

        $this->company->forceFill(['trial_ends_at' => '2030-01-01'])->save(); // still subscribed after the year ends
        Carbon::setTestNow('2027-01-10 09:00:00');
        $year = FiscalYear::firstOrFail();
        app(YearEndClosing::class)->close($year);
        $this->assertSummaryMatchesLedger();

        $this->viewing($this->riyadh);
        $this->assertSame(1000.0, $income());
        $this->viewing($this->jeddah);
        $this->assertSame(400.0, $income());

        app(YearEndClosing::class)->reopen($year->fresh());
        $this->assertSummaryMatchesLedger();

        $this->assertSame(400.0, $income());
        $this->viewing($this->riyadh);
        $this->assertSame(1000.0, $income());
    }

    public function test_the_closing_of_the_entries_of_no_branch_and_its_reversal_stay_under_no_branch(): void
    {
        $this->entry($this->riyadh, '2026-03-10', 1000);
        $this->entryWithoutBranch('2026-04-30', 600);
        $income = fn () => app(AccountingReports::class)->incomeStatement(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))['total_revenue'];
        $withoutBranch = fn (string $type) => JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('entry_type', $type)->whereNull('branch_id')->count();

        $this->company->forceFill(['trial_ends_at' => '2030-01-01'])->save(); // still subscribed after the year ends
        Carbon::setTestNow('2027-01-10 09:00:00');
        $year = FiscalYear::firstOrFail();

        // Closed and reopened with a branch chosen: the entries of no branch are closed, and reopened, in no branch.
        $this->viewing($this->riyadh);
        app(YearEndClosing::class)->close($year);
        $this->assertSame(1, $withoutBranch('closing'));
        $this->assertSummaryMatchesLedger();
        $this->assertSame(1000.0, $income());

        app(YearEndClosing::class)->reopen($year->fresh());
        $this->assertSame(1, $withoutBranch('reversal'));
        $this->assertSummaryMatchesLedger();
        $this->assertSame(1000.0, $income());
        $this->viewing('all');
        $this->assertSame(1600.0, $income());
    }
}

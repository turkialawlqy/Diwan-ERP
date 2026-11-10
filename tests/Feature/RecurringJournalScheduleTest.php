<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RecurringJournal;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\RecurringJournalRunner;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Each period of a recurring journal is posted once, on the day it was set for. */
class RecurringJournalScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function template(array $attributes): RecurringJournal
    {
        $map = app(AccountMap::class);

        return RecurringJournal::create($attributes + ['name' => 'إيجار', 'description' => 'إيجار الشهر', 'frequency' => 'monthly', 'is_active' => true,
            'lines' => [['account_id' => $map->id('rent_expense'), 'debit' => 100, 'credit' => 0], ['account_id' => $map->id('accrued_expenses'), 'debit' => 0, 'credit' => 100]]]);
    }

    protected function entryDates(RecurringJournal $template): array
    {
        return JournalEntry::where('source_type', $template->getMorphClass())->where('source_id', $template->id)->orderBy('date')->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    }

    /** What the nightly run does with one template it picked up from the due list. */
    protected function runTemplate(RecurringJournal $template): int
    {
        $count = 0;
        $runner = app(RecurringJournalRunner::class);
        (new \ReflectionMethod($runner, 'runTemplate'))->invokeArgs($runner, [$template, now(), &$count]);

        return $count;
    }

    public function test_a_second_run_that_picked_the_template_up_before_the_first_finished_posts_nothing(): void
    {
        $template = $this->template(['next_run_date' => '2026-06-01']);
        $sameTemplateInASecondRun = RecurringJournal::findOrFail($template->id);

        $this->assertSame(1, $this->runTemplate($template));
        $this->assertSame(0, $this->runTemplate($sameTemplateInASecondRun));

        $this->assertSame(['2026-06-01'], $this->entryDates($template));
        $this->assertSame('2026-07-01', $template->fresh()->next_run_date->toDateString());
    }

    public function test_a_monthly_journal_on_the_31st_comes_back_after_a_short_month(): void
    {
        $template = $this->template(['next_run_date' => '2025-01-31']);

        app(RecurringJournalRunner::class)->runDue(Carbon::parse('2025-05-01'));

        $this->assertSame(['2025-01-31', '2025-02-28', '2025-03-31', '2025-04-30'], $this->entryDates($template));
        $this->assertSame('2025-05-31', $template->fresh()->next_run_date->toDateString());
    }

    public function test_a_quarterly_and_a_yearly_journal_keep_their_day_too(): void
    {
        $quarterly = $this->template(['next_run_date' => '2025-11-30', 'frequency' => 'quarterly']);
        $yearly = $this->template(['next_run_date' => '2024-02-29', 'frequency' => 'yearly']);

        app(RecurringJournalRunner::class)->runDue(Carbon::parse('2026-06-01'));

        $this->assertSame(['2025-11-30', '2026-02-28', '2026-05-30'], $this->entryDates($quarterly));
        $this->assertSame(['2024-02-29', '2025-02-28', '2026-02-28'], $this->entryDates($yearly));
        $this->assertSame('2027-02-28', $yearly->fresh()->next_run_date->toDateString());
    }

    public function test_a_template_that_had_already_slipped_goes_back_to_its_first_day(): void
    {
        $slipped = $this->template(['next_run_date' => '2026-01-31']);
        app(RecurringJournalRunner::class)->runDue(Carbon::parse('2026-02-28'));
        $slipped->refresh()->forceFill(['next_run_date' => '2026-03-28', 'start_date' => null])->save(); // as the old code left it
        $neverRun = $this->template(['next_run_date' => '2026-08-15']);
        DB::table('recurring_journals')->where('id', $neverRun->id)->update(['start_date' => null]);

        (require database_path('migrations/2026_11_20_000001_add_start_date_to_recurring_journals.php'))->backfill();

        $this->assertSame('2026-01-31', $slipped->fresh()->start_date->toDateString());
        $this->assertSame('2026-08-15', $neverRun->fresh()->start_date->toDateString());
        $this->assertSame('2026-04-30', $slipped->fresh()->nextAfter(Carbon::parse('2026-03-28'))->toDateString());
    }
}

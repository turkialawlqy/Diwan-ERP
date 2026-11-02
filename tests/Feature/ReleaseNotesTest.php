<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use App\Services\SelfUpdate;
use App\Services\TenantManager;
use App\Support\ReleaseNotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company, 'cashier'));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_whats_new_lists_releases_newest_first(): void
    {
        $this->get(route('whats-new.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('WhatsNew/Index')->where('releases.0.version', ReleaseNotes::latest()));
    }

    public function test_notes_of_a_later_version_wait_for_its_release(): void
    {
        config(['app.version' => '1.5.1', 'release_notes' => [['version' => '1.6.0', 'items' => []], ['version' => '1.5.1', 'items' => []], ['version' => '1.5.0', 'items' => []]]]);

        $this->get(route('whats-new.index'))->assertInertia(fn ($page) => $page->has('releases', 2)->where('releases.0.version', '1.5.1'));
        $this->assertSame('1.5.1', ReleaseNotes::latest());

        config(['app.version' => '1.6.0']);
        $this->assertSame('1.6.0', ReleaseNotes::latest());
    }

    public function test_feature_requests_page_points_to_the_developer_repository(): void
    {
        $this->get(route('feature-requests.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('FeatureRequests/Index')->where('repo', SelfUpdate::REPO));
    }

    /** The page groups and filters releases by the month and year of their date, and labels each line by its type. */
    public function test_every_release_has_a_date_a_title_and_known_line_types(): void
    {
        foreach (config('release_notes') as $release) {
            $date = \DateTime::createFromFormat('!Y-m-d', $release['date'] ?? '');
            $this->assertTrue($date && $date->format('Y-m-d') === $release['date'], "{$release['version']}: date YYYY-MM-DD");
            $this->assertNotSame('', trim($release['title'] ?? ''), "{$release['version']}: title");
            foreach ($release['items'] as [$type, $text]) {
                $this->assertContains($type, ['new', 'improved', 'fixed'], "{$release['version']}: {$text}");
                $this->assertNotSame('', trim($text), $release['version']);
            }
        }
    }

    public function test_every_version_is_listed_once_newest_first(): void
    {
        $versions = array_column(config('release_notes'), 'version');

        $this->assertSame(array_unique($versions), $versions);
        $sorted = $versions;
        usort($sorted, fn ($a, $b) => version_compare($b, $a));
        $this->assertSame($sorted, $versions);
        $this->assertContains('1.0.0', $versions);
    }
}

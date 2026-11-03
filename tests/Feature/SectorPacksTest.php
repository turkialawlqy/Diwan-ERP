<?php

namespace Tests\Feature;

use App\Models\BookableService;
use App\Models\BookingResource;
use App\Models\Company;
use App\Models\ContractTemplate;
use App\Models\ServicePlan;
use App\Models\SupportCategory;
use App\Models\VisitPackage;
use App\Models\WorkType;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Saas\OnboardingChecklist;
use App\Services\Sectors\SectorPackService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sector packs: idempotent seeding of ready-made services, plans, work types, categories and templates. */
class SectorPacksTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function packs(): SectorPackService
    {
        return app(SectorPackService::class);
    }

    public function test_the_preview_lists_what_would_be_created(): void
    {
        $items = collect($this->packs()->preview('clinic'));
        $this->assertTrue($items->contains(fn ($i) => $i['name'] === 'كشف' && ! $i['exists']));
        $this->assertTrue($items->every(fn ($i) => ! $i['exists']));
    }

    public function test_applying_a_pack_seeds_services_resources_packages_and_templates(): void
    {
        $result = $this->packs()->apply($this->company, 'clinic');

        $this->assertSame(4, BookableService::count());
        $this->assertSame(2, BookingResource::count());
        $this->assertSame(1, VisitPackage::count());
        $this->assertSame(3, SupportCategory::count());
        $this->assertSame(1, ContractTemplate::count());
        $this->assertGreaterThan(0, array_sum($result['created']));

        // The seeded services work at once: bookable on the seeded resources with weekly hours.
        $service = BookableService::where('name', 'كشف')->firstOrFail();
        $this->assertSame(2, $service->resources()->count());
        $this->assertGreaterThan(0, BookingResource::where('name', 'الطبيب العام')->firstOrFail()->hours()->count());
        $this->assertSame('جلسة علاجية', VisitPackage::first()->service->name);
    }

    public function test_applying_twice_creates_nothing_new(): void
    {
        $this->packs()->apply($this->company, 'workshop');
        $counts = [WorkType::count(), SupportCategory::count(), ContractTemplate::count()];
        $again = $this->packs()->apply($this->company, 'workshop');

        $this->assertSame($counts, [WorkType::count(), SupportCategory::count(), ContractTemplate::count()]);
        $this->assertSame(0, array_sum($again['created']));
        $this->assertGreaterThan(0, $again['existing']);
        $this->assertSame(6, WorkType::count());
        $this->assertSame(180, WorkType::where('name', 'فحص شامل')->firstOrFail()->service_interval_days);
    }

    public function test_a_pack_switches_its_modules_on_and_never_anything_off(): void
    {
        $this->company->setSetting('disabled_modules', ['gym', 'bookings', 'hr'], 'general', 'json');
        $this->packs()->apply($this->company, 'gym');

        $disabled = (array) $this->company->fresh()->setting('disabled_modules', []);
        $this->assertNotContains('gym', $disabled);
        $this->assertNotContains('bookings', $disabled);
        $this->assertContains('hr', $disabled); // untouched
        $this->assertSame(3, ServicePlan::count());
        $this->assertSame(100, ServicePlan::where('name', 'عضوية سنوية')->firstOrFail()->booking_discount_percent);
    }

    public function test_the_applied_state_is_stored_and_several_packs_coexist(): void
    {
        $this->assertTrue($this->packs()->hasNone($this->company));
        $this->packs()->apply($this->company, 'clinic');
        $this->packs()->apply($this->company, 'real_estate');

        $applied = $this->packs()->applied($this->company->fresh());
        $this->assertSame(['clinic', 'real_estate'], array_keys($applied));
        $this->assertSame(config('sector_packs.version'), $applied['clinic']['version']);
        $this->assertFalse($this->packs()->hasNone($this->company->fresh()));
        $this->assertSame(2, ContractTemplate::where('kind', 'lease')->count());
    }

    public function test_an_existing_record_by_name_is_kept_untouched(): void
    {
        BookableService::create(['name' => 'كشف', 'duration_minutes' => 10, 'price' => 999, 'tax_rate' => 0, 'is_active' => true]);
        $this->packs()->apply($this->company, 'clinic');

        $this->assertSame(1, BookableService::where('name', 'كشف')->count());
        $this->assertEquals(999.0, (float) BookableService::where('name', 'كشف')->first()->price); // the owner's own data wins
    }

    public function test_the_settings_page_and_apply_action_follow_permissions(): void
    {
        $this->get(route('settings.sector-packs'))->assertOk()->assertInertia(fn ($p) => $p->component('Settings/SectorPacks')->has('packs', 4));
        $this->post(route('settings.sector-packs.apply', 'gym'))->assertSessionHasNoErrors();
        $this->assertSame(3, ServicePlan::count());
        $this->post(route('settings.sector-packs.apply', 'nope'))->assertSessionHasErrors('pack');

        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->get(route('settings.sector-packs'))->assertForbidden();
        $this->post(route('settings.sector-packs.apply', 'clinic'))->assertForbidden();
    }

    public function test_the_onboarding_step_shows_for_new_companies_only(): void
    {
        $keys = collect(app(OnboardingChecklist::class)->steps($this->company))->pluck('key');
        $this->assertTrue($keys->contains('sector')); // a fresh company still has other steps open

        $this->packs()->apply($this->company, 'clinic');
        $step = collect(app(OnboardingChecklist::class)->steps($this->company->fresh()))->firstWhere('key', 'sector');
        $this->assertTrue($step['done']);
    }
}

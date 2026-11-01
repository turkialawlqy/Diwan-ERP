<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Optional modules (factories, contracting, ...) switched on and off by the company. */
class ModuleSwitchTest extends TestCase
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

    public function test_a_switched_off_module_disappears_and_its_pages_are_refused(): void
    {
        $this->get(route('manufacturing.orders.index'))->assertOk();

        $this->get(route('settings.modules'))->assertOk()->assertInertia(fn ($p) => $p->component('Settings/Modules')->where('modules.0.enabled', true));
        $this->put(route('settings.modules.update'), ['enabled' => ['projects', 'equipment', 'pos', 'hr', 'crm']])->assertSessionHasNoErrors();

        $this->get(route('manufacturing.orders.index'))->assertForbidden();
        $this->get(route('projects.index'))->assertOk();
        $this->get(route('dashboard'))->assertInertia(fn ($p) => $p
            ->where('routePermissions', fn ($routes) => ! isset($routes['manufacturing.orders.index']) && isset($routes['projects.index'])));
        $this->getJson(route('search', ['q' => 'x']))->assertOk();

        // Back on: the pages return (the data was kept).
        $this->put(route('settings.modules.update'), ['enabled' => array_keys(config('saas.optional_modules'))])->assertSessionHasNoErrors();
        $this->get(route('manufacturing.orders.index'))->assertOk();
    }

    public function test_only_known_modules_can_be_listed(): void
    {
        $this->put(route('settings.modules.update'), ['enabled' => ['sales']])->assertSessionHasErrors('enabled.0');
    }
}

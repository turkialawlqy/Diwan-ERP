<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** «كل التقارير»: the hub page opens for signed-in users, and every link of the sidebar's Reports group has a route that is permission-mapped. */
class ReportsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hub_opens_for_a_signed_in_user_and_not_for_a_guest(): void
    {
        $this->withoutVite();
        $this->get(route('reports.index'))->assertRedirect();

        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company, 'sales'));
        $this->get(route('reports.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Reports/Index'));
        app(TenantManager::class)->forget();
    }

    public function test_every_link_in_the_reports_group_is_a_known_permission_mapped_route(): void
    {
        $nav = file_get_contents(base_path('resources/js/navigation.js'));
        preg_match("/key: 'reports'.*?\n    \},/s", $nav, $group);
        preg_match_all("/route: '([^']+)'/", $group[0] ?? '', $m);
        $this->assertGreaterThan(20, count($m[1]), 'the Reports group should hold every report link');

        $map = config('erp_permissions.routes');
        foreach ($m[1] as $name) {
            $this->assertTrue(Route::has($name), "{$name} is not a route");
            $this->assertArrayHasKey($name, $map, "{$name} has no permission mapping");
        }
        $this->assertStringNotContainsString("key: 'statements'", $nav, 'the financial statements group was folded into Reports');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The «الصفحات» tab of the global search (resources/js/pageIndex.js): every page it may list exists, opens
 * without parameters and is guarded like the sidebar; and the page list the browser builds from the shared
 * props (routePermissions, auth.user.permissions, features) holds exactly the pages the server lets the user
 * open: a hidden page is refused by URL too (403 / 404), for a role, a module switched off, a branch, a
 * country, a permission change and another company.
 */
class PageSearchIndexTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ the index sources (JS)

    /** @return array<string, array{feature: ?string, access: ?string, parent: ?string, label: ?string}> route => how the index shows it */
    protected function indexed(): array
    {
        $out = [];
        preg_match_all("/\{[^{}]*route: '([\\w.-]+)'[^{}]*\}/", File::get(resource_path('js/navigation.js')), $nav, PREG_SET_ORDER);
        foreach ($nav as [$item, $route]) {
            preg_match("/feature: '(\\w+)'/", $item, $feature);
            $out[$route] = ['feature' => $feature[1] ?? null, 'access' => null, 'parent' => null, 'label' => null];
        }
        foreach ($this->extraPages() as $route => $page) {
            $this->assertArrayNotHasKey($route, $out, "{$route} is in the sidebar and in EXTRA_PAGES");
            // A page registered under a sidebar page takes that page's country feature (pageIndex.js under()).
            $out[$route] = $page + ['feature' => $page['parent'] ? ($out[$page['parent']]['feature'] ?? null) : null];
        }

        return $out;
    }

    /** @return array<string, array{access: ?string, parent: ?string, label: ?string}> EXTRA_PAGES of pageIndex.js */
    protected function extraPages(): array
    {
        $source = File::get(resource_path('js/pageIndex.js'));
        $this->assertSame(1, preg_match('/export const EXTRA_PAGES = \[(.*?)\n\];/s', $source, $block));
        $out = [];
        foreach (array_slice(preg_split("/(?=route: ')/", $block[1]), 1) as $chunk) {
            preg_match("/^route: '([\\w.-]+)'/", $chunk, $route);
            preg_match("/access: '(\\w+)'/", $chunk, $access);
            preg_match("/parent: '([\\w.-]+)'/", $chunk, $parent);
            preg_match("/label: '([\\w.]+)'/", $chunk, $label);
            $out[$route[1]] = ['access' => $access[1] ?? null, 'parent' => $parent[1] ?? null, 'label' => $label[1] ?? null];
        }
        $this->assertNotEmpty($out);

        return $out;
    }

    /** The source of one `export const NAME = {...};` block of pageIndex.js. */
    protected function block(string $name): string
    {
        $this->assertSame(1, preg_match('/export const '.$name.' = \{(.*?)\n\};/s', File::get(resource_path('js/pageIndex.js')), $m), $name);

        return $m[1];
    }

    protected function translation(array $tree, string $key): ?string
    {
        foreach (explode('.', $key) as $part) {
            if (! is_array($tree) || ! array_key_exists($part, $tree)) {
                return null;
            }
            $tree = $tree[$part];
        }

        return is_string($tree) ? $tree : null;
    }

    public function test_every_indexed_page_exists_opens_without_parameters_and_is_guarded(): void
    {
        $map = config('erp_permissions.routes');
        $indexed = $this->indexed();
        $this->assertGreaterThan(150, count($indexed));

        foreach ($indexed as $name => $page) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Indexed route [{$name}] does not exist.");
            $this->assertContains('GET', $route->methods(), "[{$name}] is not a page (GET).");
            $this->assertDoesNotMatchRegularExpression('/\{[^}?]+\}/', $route->uri(), "[{$name}] needs a parameter: a page with a record id is never indexed.");
            $middleware = $route->gatherMiddleware();

            if ($page['access'] === null) {
                // Shown through canRoute: the route is in the tenant group and mapped, like the sidebar.
                $this->assertContains('erp.authorize', $middleware, "[{$name}] is not behind erp.authorize.");
                $this->assertArrayHasKey($name, $map, "[{$name}] has no permission mapping (the search would hide it for everyone).");
            } elseif ($page['access'] === 'authenticated') {
                $this->assertContains('auth', $middleware, "[{$name}] must need a signed-in user.");
            } else {
                $this->assertSame('super_admin', $page['access'], "[{$name}] has an unknown access.");
                $this->assertContains('super_admin', $middleware, "[{$name}] must be for super admins only.");
            }
        }
    }

    public function test_the_registry_names_only_indexed_pages_and_its_texts_exist_in_both_languages(): void
    {
        $indexed = $this->indexed();
        $ar = json_decode(File::get(resource_path('js/locales/ar.json')), true);
        $en = json_decode(File::get(resource_path('js/locales/en.json')), true);

        preg_match_all("/^\\s+'?([\\w.-]+)'?: \\[/m", $this->block('PAGE_KEYWORDS'), $keywords);
        preg_match_all("/^\\s+'?([\\w.-]+)'?: [\\['](.*?)\\]?,?$/m", $this->block('PAGE_DESCRIPTIONS'), $descriptions, PREG_SET_ORDER);
        $this->assertNotEmpty($keywords[1]);
        $this->assertNotEmpty($descriptions);
        foreach ($keywords[1] as $route) {
            $this->assertArrayHasKey($route, $indexed, "PAGE_KEYWORDS names [{$route}], which the index does not hold.");
        }

        $keys = [];
        foreach ($descriptions as [, $route, $value]) {
            $this->assertArrayHasKey($route, $indexed, "PAGE_DESCRIPTIONS names [{$route}], which the index does not hold.");
            preg_match_all("/'?([a-z0-9_]+\\.[a-z0-9_.]+)'?/", "'".$value, $m);
            array_push($keys, ...$m[1]);
        }
        preg_match_all("/'t:([a-z0-9_]+\\.[a-z0-9_.]+)'/", File::get(resource_path('js/pageIndex.js')), $t);
        $this->assertNotEmpty($t[1]);
        array_push($keys, ...$t[1]);
        foreach ($this->extraPages() as $route => $page) {
            $keys[] = $page['label'];
            if ($page['parent']) {
                $this->assertArrayHasKey($page['parent'], $indexed, "[{$route}]'s parent is not a sidebar page.");
                $this->assertNull($indexed[$page['parent']]['access'], "[{$route}]'s parent is not a sidebar page.");
            }
        }
        preg_match_all("/description: '([a-z0-9_.]+)'/", File::get(resource_path('js/pageIndex.js')), $extraDescriptions);
        array_push($keys, ...$extraDescriptions[1]);

        foreach (array_unique($keys) as $key) {
            $this->assertNotEmpty($this->translation($ar, $key), "[{$key}] is missing in ar.json.");
            $this->assertNotEmpty($this->translation($en, $key), "[{$key}] is missing in en.json.");
        }
    }

    // ------------------------------------------------------------ what the browser builds vs what the server allows

    /** The shared props of a page every signed-in user may open. */
    protected function props(): array
    {
        return $this->get(route('notifications.index'))->assertOk()->viewData('page')['props'];
    }

    /** The pages the browser index would hold for these props: pageIndex.js openUrl() with canRoute (permissions.js). */
    protected function visible(array $props): array
    {
        $routes = $props['routePermissions'];
        $user = $props['auth']['user'];
        $can = fn (string $p) => $user['is_owner'] || in_array($p, collect($user['permissions'])->all(), true);
        $canRoute = function (string $name) use ($routes, $user, $can) {
            if (! array_key_exists($name, $routes)) {
                return false;
            }
            $required = $routes[$name];
            if ($required === '@authenticated' || $user['is_owner']) {
                return true;
            }
            foreach ((array) $required as $p) {
                if ($can($p)) {
                    return true;
                }
            }

            return false;
        };

        $out = [];
        foreach ($this->indexed() as $name => $page) {
            if ($page['feature'] && ! in_array($page['feature'], $props['features'], true)) {
                continue;
            }
            $allowed = match ($page['access']) {
                'authenticated' => true,
                'super_admin' => (bool) $user['is_super_admin'],
                default => $canRoute($name),
            };
            if ($allowed) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** Every indexed tenant page: hidden ⇒ refused by URL (403 / 404); shown ⇒ opens. Returns the shown ones. */
    protected function assertIndexMatchesServer(array $only = []): array
    {
        $visible = $this->visible($this->props());
        foreach ($this->indexed() as $name => $page) {
            if ($page['access'] === 'super_admin' || ($only && ! in_array($name, $only, true))) {
                continue;
            }
            $status = $this->get(route($name))->getStatusCode();
            if (in_array($name, $visible, true)) {
                $this->assertTrue($status < 400, "[{$name}] is in the search but answered {$status}.");
            } else {
                $this->assertContains($status, [403, 404], "[{$name}] is not in the search but answered {$status} by URL.");
            }
        }

        return $visible;
    }

    protected function user(string $role, array $permissions = []): User
    {
        $user = $this->userWithRole($this->company, $role);
        if ($permissions) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
            $user->givePermissionTo($permissions);
        }
        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    public function test_a_restricted_role_sees_only_what_it_may_open_and_the_rest_is_refused_by_url(): void
    {
        $this->user('cashier');
        $visible = $this->assertIndexMatchesServer();

        $this->assertContains('pos.index', $visible);
        $this->assertContains('sales.clients.index', $visible);
        $this->assertContains('notifications.index', $visible);
        $this->assertContains('profile.edit', $visible);
        foreach (['accounting.ledger.index', 'accounting.journals.index', 'settings.index', 'settings.sector-packs', 'users.index', 'hr.payroll.index'] as $hidden) {
            $this->assertNotContains($hidden, $visible);
        }
    }

    public function test_the_owner_sees_the_pages_outside_the_sidebar_but_not_the_platform_panel(): void
    {
        $this->user('admin');
        $visible = $this->visible($this->props());
        foreach (['settings.sector-packs', 'purchases.invoices.from-receipts', 'notifications.index', 'profile.edit', 'accounting.ledger.index', 'sales.customer-groups.index'] as $name) {
            $this->assertContains($name, $visible);
        }
        $this->assertNotContains('super-admin.dashboard', $visible);
        $this->assertIndexMatchesServer(['settings.sector-packs', 'purchases.invoices.from-receipts', 'notifications.index', 'profile.edit', 'accounting.ledger.index', 'sales.customer-groups.index']);
    }

    public function test_a_module_switched_off_leaves_the_search_and_its_pages_are_refused_then_come_back(): void
    {
        $this->user('admin');
        $manufacturing = array_values(array_filter(array_keys($this->indexed()), fn ($r) => str_starts_with($r, 'manufacturing.')));
        $this->assertNotEmpty($manufacturing);
        $this->assertEmpty(array_diff($manufacturing, $this->visible($this->props())));

        $this->company->setSetting('disabled_modules', ['manufacturing'], 'general', 'json');
        $this->assertEmpty(array_intersect($manufacturing, $this->visible($this->props())));
        $this->assertIndexMatchesServer($manufacturing);

        $this->company->setSetting('disabled_modules', [], 'general', 'json');
        $this->assertEmpty(array_diff($manufacturing, $this->visible($this->props())));
        $this->assertIndexMatchesServer($manufacturing);
    }

    public function test_a_permission_granted_or_removed_mid_session_reaches_the_next_page(): void
    {
        $user = $this->user('employee');
        $this->assertNotContains('accounting.ledger.index', $this->visible($this->props()));
        $this->get(route('accounting.ledger.index'))->assertForbidden();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo('accounting.ledger.view');
        $this->actingAs($user->fresh());
        $this->assertContains('accounting.ledger.index', $this->visible($this->props()));
        $this->assertIndexMatchesServer(['accounting.ledger.index']);

        $user->revokePermissionTo('accounting.ledger.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh());
        $this->assertNotContains('accounting.ledger.index', $this->visible($this->props()));
        $this->get(route('accounting.ledger.index'))->assertForbidden();
    }

    public function test_the_branch_worked_in_decides_its_activities_and_country_pages(): void
    {
        $owner = $this->user('admin');
        $riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true, 'country_code' => 'SA', 'modules' => ['clinic']]);
        $doha = Branch::create(['name_ar' => 'الدوحة', 'code' => 'DOH', 'is_active' => true, 'country_code' => 'QA', 'modules' => ['workshop']]);
        $owner->forceFill(['branch_id' => $riyadh->id])->save();
        $this->actingAs($owner->fresh());
        $pages = ['clinic.patients.index', 'workshop.vehicles.index', 'accounting.reports.vat-return'];

        $this->post(route('context.branch'), ['branch_id' => $riyadh->id]);
        $visible = $this->assertIndexMatchesServer($pages);
        $this->assertContains('clinic.patients.index', $visible);
        $this->assertNotContains('workshop.vehicles.index', $visible);
        $this->assertContains('accounting.reports.vat-return', $visible);

        // Qatar has no VAT and the branch runs the workshop only.
        $this->post(route('context.branch'), ['branch_id' => $doha->id]);
        $visible = $this->assertIndexMatchesServer($pages);
        $this->assertNotContains('clinic.patients.index', $visible);
        $this->assertContains('workshop.vehicles.index', $visible);
        $this->assertNotContains('accounting.reports.vat-return', $visible);
    }

    public function test_each_company_gets_its_own_pages(): void
    {
        $this->user('admin');
        $this->assertContains('manufacturing.orders.index', $this->visible($this->props()));

        $other = Company::factory()->create();
        app(TenantManager::class)->setTenant($other);
        app(AccountingSetupService::class)->provision($other);
        $other->setSetting('disabled_modules', ['manufacturing'], 'general', 'json');
        $this->actingAs($this->userWithRole($other));
        $props = $this->props();
        $this->assertSame($other->id, $props['tenant']['id']);
        $this->assertNotContains('manufacturing.orders.index', $this->visible($props));
        $this->get(route('manufacturing.orders.index'))->assertForbidden();

        // Back in the first company: its own modules again.
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs(User::where('company_id', $this->company->id)->first());
        $props = $this->props();
        $this->assertSame($this->company->id, $props['tenant']['id']);
        $this->assertContains('manufacturing.orders.index', $this->visible($props));
    }
}

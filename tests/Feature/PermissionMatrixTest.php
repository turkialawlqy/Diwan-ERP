<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Permissions\PermissionRegistry;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function role(string $name, ?Company $company = null): Role
    {
        return Role::where('name', $name)->where('team_id', ($company ?? $this->company)->id)->firstOrFail();
    }

    public function test_every_tenant_route_is_mapped_to_a_registered_permission(): void
    {
        $registry = app(PermissionRegistry::class);
        $map = config('erp_permissions.routes');
        $names = $registry->names();

        foreach (Route::getRoutes() as $route) {
            if (! in_array('erp.authorize', $route->gatherMiddleware(), true)) {
                continue;
            }

            $this->assertArrayHasKey($route->getName(), $map, "Route [{$route->getName()}] has no permission mapping.");

            foreach ((array) $map[$route->getName()] as $permission) {
                if ($permission !== '@authenticated') {
                    $this->assertTrue($names->contains($permission), "Unknown permission [{$permission}] for [{$route->getName()}].");
                }
            }
        }
    }

    public function test_default_roles_are_created_per_company(): void
    {
        $other = Company::factory()->create();
        $this->userWithRole($this->company);
        $this->userWithRole($other);

        $this->assertNotEquals($this->role('admin')->id, $this->role('admin', $other)->id);
        $this->assertSame(
            Role::where('team_id', $this->company->id)->count(),
            count(config('erp_permissions.default_roles'))
        );
    }

    public function test_user_without_permission_gets_403_and_with_permission_200(): void
    {
        $cashier = $this->userWithRole($this->company, 'cashier');

        $this->actingAs($cashier)->get('/sales/invoices')->assertForbidden();
        $this->actingAs($cashier)->get('/accounting/accounts')->assertForbidden();
        $this->actingAs($cashier)->get('/pos/history')->assertOk();
    }

    public function test_owner_can_open_everything(): void
    {
        $owner = $this->userWithRole($this->company);

        $this->actingAs($owner)->get('/accounting/accounts')->assertOk();
        $this->actingAs($owner)->get('/roles')->assertOk();
        $this->actingAs($owner)->get('/audit-log')->assertOk();
    }

    public function test_granting_permission_through_matrix_takes_effect_immediately(): void
    {
        $owner = $this->userWithRole($this->company);
        $cashier = $this->userWithRole($this->company, 'cashier');

        $this->actingAs($cashier)->get('/sales/invoices')->assertForbidden();

        $role = $this->role('cashier');
        $permissions = $role->permissions->pluck('name')->push('sales.invoices.view')->all();

        $this->actingAs($owner)
            ->put("/roles/{$role->id}/permissions", ['permissions' => $permissions])
            ->assertSessionHasNoErrors();

        $this->actingAs($cashier->fresh())->get('/sales/invoices')->assertOk();

        // And revoking it removes access again.
        $this->actingAs($owner)
            ->put("/roles/{$role->id}/permissions", ['permissions' => ['pos.terminal.use']])
            ->assertSessionHasNoErrors();

        $this->actingAs($cashier->fresh())->get('/pos/history')->assertForbidden();
    }

    public function test_direct_user_permission_grants_access(): void
    {
        $owner = $this->userWithRole($this->company);
        $employee = $this->userWithRole($this->company, 'employee');

        $this->actingAs($owner)
            ->put("/users/{$employee->id}/permissions", ['permissions' => ['reports.vat.view']])
            ->assertSessionHasNoErrors();

        $this->actingAs($employee->fresh())->get('/reports/vat')->assertOk();
        $this->actingAs($employee->fresh())->get('/reports/sales')->assertForbidden();
    }

    public function test_owner_role_cannot_be_edited_or_deleted(): void
    {
        $owner = $this->userWithRole($this->company);
        $ownerRole = $this->role('admin');

        $this->actingAs($owner)->put("/roles/{$ownerRole->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->actingAs($owner)->delete("/roles/{$ownerRole->id}")->assertForbidden();
    }

    public function test_cannot_touch_another_companys_role(): void
    {
        $other = Company::factory()->create();
        $this->userWithRole($other);
        $owner = $this->userWithRole($this->company);

        $foreignRole = $this->role('cashier', $other);

        $this->actingAs($owner)->put("/roles/{$foreignRole->id}/permissions", ['permissions' => []])->assertNotFound();
        $this->actingAs($owner)->delete("/roles/{$foreignRole->id}")->assertNotFound();
    }

    public function test_non_owner_cannot_escalate_privileges(): void
    {
        $manager = $this->userWithRole($this->company, 'hr_manager');

        // Give the HR manager user-management rights only.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $manager->givePermissionTo(['admin.users.view', 'admin.users.create', 'admin.users.edit', 'admin.roles.view', 'admin.roles.edit']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Cannot create an owner.
        $this->actingAs($manager)->post('/users', [
            'name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'password123',
            'role' => 'admin', 'status' => 'active',
        ])->assertSessionHasErrors('role');

        // Cannot assign a role holding permissions they lack (accountant).
        $this->actingAs($manager)->post('/users', [
            'name' => 'Acc', 'email' => 'acc@example.com', 'password' => 'password123',
            'role' => 'accountant', 'status' => 'active',
        ])->assertSessionHasErrors('role');

        // Cannot grant a permission they do not hold through the matrix.
        $role = $this->role('employee');
        $this->actingAs($manager)->put("/roles/{$role->id}/permissions", [
            'permissions' => ['accounting.journals.create'],
        ])->assertSessionHasErrors('permissions');
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $owner = $this->userWithRole($this->company);

        $this->actingAs($owner)->put("/users/{$owner->id}", [
            'name' => $owner->name, 'email' => $owner->email,
            'role' => 'cashier', 'status' => 'active',
        ])->assertSessionHasErrors('role');
    }

    public function test_shared_props_expose_permissions_for_ui(): void
    {
        $cashier = $this->userWithRole($this->company, 'cashier');

        $this->actingAs($cashier)->get('/pos/history')->assertInertia(fn ($page) => $page
            ->where('auth.user.is_owner', false)
            ->where('auth.user.permissions', fn ($p) => collect($p)->contains('pos.history.view') && ! collect($p)->contains('sales.invoices.view'))
            ->where('routePermissions', fn ($map) => ($map['sales.invoices.index'] ?? null) === 'sales.invoices.view')
        );
    }
}

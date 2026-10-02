<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use App\Services\Permissions\PermissionRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user of the company holding the given role (default: owner),
     * seeding the company's default roles first.
     */
    protected function userWithRole(Company $company, ?string $role = null, array $attributes = []): User
    {
        $registry = app(PermissionRegistry::class);
        $registry->seedCompanyRoles($company);

        $user = User::factory()->create(array_merge(['company_id' => $company->id, 'status' => 'active'], $attributes));

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);
        $user->assignRole($role ?? $registry->ownerRole());
        $registrar->setPermissionsTeamId($previous);

        return $user->fresh();
    }
}

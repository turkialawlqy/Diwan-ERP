<?php

namespace App\Services\Permissions;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads config/erp_permissions.php and keeps the database in sync with it:
 * global permission rows plus per-company (team) default roles.
 */
class PermissionRegistry
{
    public function config(): array
    {
        return config('erp_permissions');
    }

    public function ownerRole(): string
    {
        return config('erp_permissions.owner_role', 'admin');
    }

    /**
     * Every permission name defined in the registry.
     */
    public function names(): Collection
    {
        $names = [];

        foreach ($this->config()['modules'] as $moduleKey => $module) {
            foreach ($module['resources'] as $resourceKey => $resource) {
                foreach ($resource['actions'] as $action) {
                    $names[] = "{$moduleKey}.{$resourceKey}.{$action}";
                }
            }
        }

        return collect($names);
    }

    /**
     * Expand '*' / 'module.*' / 'module.resource.*' patterns to concrete names.
     */
    public function expand(array|string $patterns): Collection
    {
        $all = $this->names();

        if ($patterns === '*') {
            return $all;
        }

        return collect((array) $patterns)
            ->flatMap(fn ($pattern) => str_contains($pattern, '*')
                ? $all->filter(fn ($name) => Str::is($pattern, $name))
                : [$pattern])
            ->intersect($all)
            ->unique()
            ->values();
    }

    /**
     * Matrix structure for the UI: modules -> resources -> actions.
     */
    public function matrix(string $locale = 'ar'): array
    {
        $config = $this->config();

        return [
            'actions' => collect($config['actions'])->map(fn ($a, $key) => ['key' => $key, 'label' => $a[$locale] ?? $a['ar']])->values(),
            'modules' => collect($config['modules'])->map(fn ($module, $moduleKey) => [
                'key' => $moduleKey,
                'label' => $module[$locale] ?? $module['ar'],
                'resources' => collect($module['resources'])->map(fn ($resource, $resourceKey) => [
                    'key' => $resourceKey,
                    'label' => $resource[$locale] ?? $resource['ar'],
                    'actions' => $resource['actions'],
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Create missing permissions and drop ones no longer in the registry.
     */
    public function syncPermissions(): void
    {
        $names = $this->names();
        $existing = Permission::where('guard_name', 'web')->pluck('name');

        foreach ($names->diff($existing) as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        Permission::where('guard_name', 'web')->whereNotIn('name', $names)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Ensure the company has every default role. Newly created roles get their
     * default permissions; the owner role is always refreshed to "everything".
     * Existing customised roles are left untouched unless $reset is true.
     */
    public function seedCompanyRoles(Company $company, bool $reset = false): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->id);

        try {
            foreach ($this->config()['default_roles'] as $name => $definition) {
                $role = Role::where('name', $name)
                    ->where('guard_name', 'web')
                    ->where(config('permission.column_names.team_foreign_key'), $company->id)
                    ->first();

                $isNew = ! $role;

                if ($isNew) {
                    $role = Role::create(['name' => $name, 'guard_name' => 'web', 'team_id' => $company->id]);
                }

                if ($isNew || $reset || $name === $this->ownerRole()) {
                    $role->syncPermissions($this->expand($definition['permissions'])->all());
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    public function roleLabel(string $name, string $locale = 'ar'): string
    {
        $definition = $this->config()['default_roles'][$name] ?? null;

        return $definition[$locale] ?? $definition['ar'] ?? $name;
    }

    public function isDefaultRole(string $name): bool
    {
        return array_key_exists($name, $this->config()['default_roles']);
    }
}

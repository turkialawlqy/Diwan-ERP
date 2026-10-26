<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Permissions\PermissionRegistry;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Company roles and the permission matrix. Roles are per company (Spatie team
 * = company); the owner role always has every permission and is read-only.
 */
class RoleController extends Controller
{
    public function __construct(
        protected PermissionRegistry $registry,
        protected TenantManager $tenantManager,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->tenantManager->getTenantId();
        $teamKey = config('permission.column_names.team_foreign_key');

        $usersPerRole = DB::table(config('permission.table_names.model_has_roles'))
            ->where($teamKey, $companyId)
            ->select('role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $roles = $this->companyRoles()
            ->with('permissions:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => $this->presentRole($role, (int) ($usersPerRole[$role->id] ?? 0)));

        $selectedId = (int) $request->query('role', $roles->first()['id'] ?? 0);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'matrix' => $this->registry->matrix(app()->getLocale()),
            'selectedRoleId' => $selectedId,
            'grantable' => $this->grantablePermissions($request),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', $this->uniqueRoleName()],
            'copy_from' => ['nullable', 'integer'],
        ]);

        $role = Role::create([
            'name' => trim($validated['name']),
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key') => $this->tenantManager->getTenantId(),
        ]);

        if (! empty($validated['copy_from'])) {
            $source = $this->findCompanyRole((int) $validated['copy_from']);
            $permissions = $source->name === $this->registry->ownerRole()
                ? $this->registry->names()->all()
                : $source->permissions->pluck('name')->all();
            $role->syncPermissions($this->limitToGrantable($request, $permissions));
        }

        AuditLog::record('role_created', $role, [], ['name' => $role->name]);
        $this->flushCache();

        return redirect()->route('roles.index', ['role' => $role->id])
            ->with('success', 'تم إنشاء الدور بنجاح');
    }

    public function update(Request $request, Role $role)
    {
        $this->assertCompanyRole($role);
        $this->assertNotOwnerRole($role);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', $this->uniqueRoleName($role->id)],
        ]);

        if ($this->registry->isDefaultRole($role->name)) {
            return back()->withErrors(['name' => 'لا يمكن إعادة تسمية الأدوار الافتراضية للنظام.']);
        }

        $old = $role->name;
        $role->update(['name' => trim($validated['name'])]);

        AuditLog::record('role_renamed', $role, ['name' => $old], ['name' => $role->name]);
        $this->flushCache();

        return back()->with('success', 'تم تعديل اسم الدور بنجاح');
    }

    /**
     * Save the full permission set of a role from the matrix.
     */
    public function updatePermissions(Request $request, Role $role)
    {
        $this->assertCompanyRole($role);
        $this->assertNotOwnerRole($role);

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($this->registry->names()->all())],
        ]);

        $requested = collect($validated['permissions'])->unique()->values();
        $current = $role->permissions->pluck('name');

        // A non-owner may only add or remove permissions they hold themselves.
        $grantable = collect($this->grantablePermissions($request));
        $changed = $requested->diff($current)->merge($current->diff($requested));
        $forbidden = $changed->diff($grantable);

        if ($forbidden->isNotEmpty()) {
            return back()->withErrors([
                'permissions' => 'لا يمكنك منح أو سحب صلاحيات لا تملكها: '.$forbidden->implode('، '),
            ]);
        }

        $role->syncPermissions($requested->all());

        AuditLog::record('role_permissions_updated', $role, [
            'permissions' => $current->values()->all(),
        ], [
            'permissions' => $requested->all(),
        ]);
        $this->flushCache();

        return back()->with('success', 'تم حفظ صلاحيات الدور بنجاح');
    }

    public function duplicate(Request $request, Role $role)
    {
        $this->assertCompanyRole($role);

        $request->merge(['copy_from' => $role->id, 'name' => $request->input('name', $role->name.' - نسخة')]);

        return $this->store($request);
    }

    public function destroy(Role $role)
    {
        $this->assertCompanyRole($role);
        $this->assertNotOwnerRole($role);

        $assigned = DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->id)
            ->exists();

        if ($assigned) {
            return back()->withErrors(['role' => 'لا يمكن حذف دور مسند لمستخدمين. قم بتغيير أدوارهم أولاً.']);
        }

        AuditLog::record('role_deleted', $role, ['name' => $role->name]);
        $role->delete();
        $this->flushCache();

        return redirect()->route('roles.index')->with('success', 'تم حذف الدور بنجاح');
    }

    protected function presentRole(Role $role, int $usersCount): array
    {
        $isOwner = $role->name === $this->registry->ownerRole();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $this->registry->roleLabel($role->name, app()->getLocale()),
            'is_owner' => $isOwner,
            'is_system' => $this->registry->isDefaultRole($role->name),
            'users_count' => $usersCount,
            'permissions' => $isOwner
                ? $this->registry->names()->all()
                : $role->permissions->pluck('name')->values()->all(),
        ];
    }

    protected function companyRoles()
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where(config('permission.column_names.team_foreign_key'), $this->tenantManager->getTenantId());
    }

    protected function findCompanyRole(int $id): Role
    {
        return $this->companyRoles()->with('permissions')->findOrFail($id);
    }

    protected function assertCompanyRole(Role $role): void
    {
        $teamKey = config('permission.column_names.team_foreign_key');

        if ((int) $role->{$teamKey} !== (int) $this->tenantManager->getTenantId()) {
            abort(404);
        }
    }

    protected function assertNotOwnerRole(Role $role): void
    {
        if ($role->name === $this->registry->ownerRole()) {
            abort(403, 'دور مدير المنشأة يملك جميع الصلاحيات ولا يمكن تعديله أو حذفه.');
        }
    }

    protected function uniqueRoleName(?int $ignoreId = null)
    {
        $teamKey = config('permission.column_names.team_foreign_key');

        return Rule::unique('roles', 'name')
            ->where($teamKey, $this->tenantManager->getTenantId())
            ->where('guard_name', 'web')
            ->ignore($ignoreId);
    }

    /**
     * Permissions the current user may grant: everything for the owner,
     * otherwise only the permissions they hold.
     */
    protected function grantablePermissions(Request $request): array
    {
        $user = $request->user();

        if ($user->isCompanyOwner() || ($user->isSuperAdmin() && $this->tenantManager->isImpersonating())) {
            return $this->registry->names()->all();
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    protected function limitToGrantable(Request $request, array $permissions): array
    {
        return array_values(array_intersect($permissions, $this->grantablePermissions($request)));
    }

    protected function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

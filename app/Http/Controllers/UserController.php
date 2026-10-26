<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\PermissionRegistry;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function __construct(
        protected PermissionRegistry $registry,
        protected TenantManager $tenantManager,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->tenantManager->getTenantId();

        $users = User::with(['branch', 'branches:id,name_ar', 'roles:id,name'])
            ->where('company_id', $companyId)
            ->where('is_super_admin', false)
            ->when($request->query('search'), function ($q, $search) {
                $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($this->perPage($request, 15))
            ->withQueryString();

        $users->getCollection()->transform(function (User $user) {
            $user->setAttribute('direct_permissions', $user->getDirectPermissions()->pluck('name')->values());

            return $user;
        });

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $roles = $this->companyRoles()->orderBy('id')->get(['id', 'name'])
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $this->registry->roleLabel($role->name, app()->getLocale()),
                'is_owner' => $role->name === $this->registry->ownerRole(),
            ]);

        $stats = [
            'total_users' => User::where('company_id', $companyId)->where('is_super_admin', false)->count(),
            'active_users' => User::where('company_id', $companyId)->where('status', 'active')->where('is_super_admin', false)->count(),
        ];

        return Inertia::render('Users/Index', [
            'users' => $users,
            'branches' => $branches,
            'roles' => $roles,
            'stats' => $stats,
            'matrix' => $this->registry->matrix(app()->getLocale()),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->tenantManager->getTenantId();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Emails are globally unique: login is by email and must map to one company.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|tenant_exists:branches,id',
            'role' => ['required', 'string', $this->companyRoleRule()],
            'status' => 'required|string|in:active,inactive',
        ]);

        $this->assertMayAssignRole($request, $validated['role']);
        $this->enforceUserLimit();
        $validated = $this->resolveBranches($validated);

        $user = DB::transaction(function () use ($validated, $companyId) {
            $user = (new User)->forceFill([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => $validated['status'],
                'is_super_admin' => false,
                // Added by the company's administrator, who vouches for the address.
                'email_verified_at' => now(),
            ]);
            $user->save();

            $user->assignRole($validated['role']);
            $user->branches()->sync($validated['branch_ids']);

            return $user;
        });

        AuditLog::record('user_created', $user, [], ['email' => $user->email, 'role' => $validated['role']]);
        $this->flushCache();

        return redirect()->route('users.index')
            ->with('success', 'تم إنشاء المستخدم بنجاح');
    }

    public function update(Request $request, User $user)
    {
        $this->assertCompanyUser($user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::defaults()],
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|tenant_exists:branches,id',
            'role' => ['required', 'string', $this->companyRoleRule()],
            'status' => 'required|string|in:active,inactive',
        ]);

        $this->assertMayManageUser($request, $user);
        $this->assertMayAssignRole($request, $validated['role']);
        $validated = $this->resolveBranches($validated);

        $owner = $this->registry->ownerRole();
        $losingOwnership = $user->hasRole($owner)
            && ($validated['role'] !== $owner || $validated['status'] !== 'active');

        if ($losingOwnership && $this->ownersCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'يجب أن يبقى مدير منشأة واحد نشط على الأقل.',
            ]);
        }

        $oldRole = $user->getRoleNames()->first();

        DB::transaction(function () use ($user, $validated) {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => $validated['status'],
            ]);

            if (! empty($validated['password'])) {
                $user->update(['password' => Hash::make($validated['password'])]);
            }

            $user->syncRoles([$validated['role']]);
            $user->branches()->sync($validated['branch_ids']);
            $user->forgetAllowedBranches();

            // A new password or a deactivation ends the user's other sessions and API tokens.
            if (! empty($validated['password']) || $validated['status'] !== 'active') {
                $user->revokeAccess();
            }
        });

        AuditLog::record('user_updated', $user, ['role' => $oldRole], ['role' => $validated['role'], 'status' => $validated['status']]);
        $this->flushCache();

        return redirect()->route('users.index')
            ->with('success', 'تم تحديث بيانات المستخدم بنجاح');
    }

    /**
     * Grant extra permissions directly to a user, on top of their role.
     */
    public function updatePermissions(Request $request, User $user)
    {
        $this->assertCompanyUser($user);
        $this->assertMayManageUser($request, $user);

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($this->registry->names()->all())],
        ]);

        $requested = collect($validated['permissions'])->unique()->values();
        $current = $user->getDirectPermissions()->pluck('name');
        $changed = $requested->diff($current)->merge($current->diff($requested));
        $forbidden = $changed->diff($this->grantablePermissions($request));

        if ($forbidden->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'لا يمكنك منح أو سحب صلاحيات لا تملكها: '.$forbidden->implode('، '),
            ]);
        }

        $user->syncPermissions($requested->all());

        AuditLog::record('user_permissions_updated', $user, ['permissions' => $current->all()], ['permissions' => $requested->all()]);
        $this->flushCache();

        return back()->with('success', 'تم حفظ الصلاحيات الإضافية للمستخدم');
    }

    public function destroy(Request $request, User $user)
    {
        $this->assertCompanyUser($user);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'لا يمكنك حذف حسابك الحالي أثناء تسجيل الدخول.']);
        }

        $this->assertMayManageUser($request, $user);

        if ($user->hasRole($this->registry->ownerRole()) && $this->ownersCount() <= 1) {
            return back()->withErrors(['user' => 'لا يمكن حذف مدير المنشأة الوحيد.']);
        }

        // Users who issued documents are kept (deactivated) so history still names them.
        if ($user->hasActivity()) {
            $user->update(['status' => 'inactive']);
            $user->revokeAccess();
            AuditLog::record('user_deactivated', $user, ['email' => $user->email]);
            $this->flushCache();

            return redirect()->route('users.index')
                ->with('success', 'للمستخدم حركات مسجلة، لذلك تم إيقافه بدلاً من حذفه.');
        }

        AuditLog::record('user_deleted', $user, ['email' => $user->email]);
        $user->revokeAccess();
        $user->delete();
        $this->flushCache();

        return redirect()->route('users.index')
            ->with('success', 'تم حذف المستخدم بنجاح');
    }

    protected function companyRoles()
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where(config('permission.column_names.team_foreign_key'), $this->tenantManager->getTenantId());
    }

    protected function companyRoleRule()
    {
        return Rule::exists('roles', 'name')
            ->where('guard_name', 'web')
            ->where(config('permission.column_names.team_foreign_key'), $this->tenantManager->getTenantId());
    }

    protected function assertCompanyUser(User $user): void
    {
        if ((int) $user->company_id !== (int) $this->tenantManager->getTenantId() || $user->is_super_admin) {
            abort(404);
        }
    }

    protected function isPrivileged(Request $request): bool
    {
        $actor = $request->user();

        return $actor->isCompanyOwner() || ($actor->isSuperAdmin() && $this->tenantManager->isImpersonating());
    }

    /**
     * Only an owner may manage another owner's account, and a non-owner may
     * only manage users whose permissions they hold themselves (otherwise
     * resetting their password would hand over the higher access).
     */
    protected function assertMayManageUser(Request $request, User $user): void
    {
        if ($user->hasRole($this->registry->ownerRole()) && ! $this->isPrivileged($request)) {
            abort(403, 'فقط مدير المنشأة يمكنه تعديل حساب مدير آخر.');
        }

        if (! $this->isPrivileged($request) && $user->getAllPermissions()->pluck('name')->diff($this->grantablePermissions($request))->isNotEmpty()) {
            abort(403, 'لا يمكنك إدارة حساب مستخدم يملك صلاحيات أعلى من صلاحياتك.');
        }
    }

    /**
     * Prevent privilege escalation: a non-owner can only assign roles whose
     * permissions they hold themselves, and never the owner role.
     */
    protected function assertMayAssignRole(Request $request, string $roleName): void
    {
        if ($this->isPrivileged($request)) {
            return;
        }

        if ($roleName === $this->registry->ownerRole()) {
            throw ValidationException::withMessages(['role' => 'فقط مدير المنشأة يمكنه إسناد دور مدير المنشأة.']);
        }

        $role = $this->companyRoles()->where('name', $roleName)->with('permissions')->firstOrFail();
        $missing = $role->permissions->pluck('name')->diff($this->grantablePermissions($request));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role' => 'لا يمكنك إسناد دور يملك صلاحيات أعلى من صلاحياتك.',
            ]);
        }
    }

    protected function grantablePermissions(Request $request)
    {
        if ($this->isPrivileged($request)) {
            return $this->registry->names();
        }

        return $request->user()->getAllPermissions()->pluck('name');
    }

    protected function ownersCount(): int
    {
        $ownerRoleId = $this->companyRoles()->where('name', $this->registry->ownerRole())->value('id');

        return User::where('company_id', $this->tenantManager->getTenantId())
            ->where('status', 'active')
            ->whereIn('id', DB::table(config('permission.table_names.model_has_roles'))
                ->where('role_id', $ownerRoleId)
                ->where('model_type', User::class)
                ->select('model_id'))
            ->count();
    }

    /**
     * Respect the subscription plan's user limit.
     */
    protected function enforceUserLimit(): void
    {
        // Same rule as every other plan limit (-1 / null = unlimited).
        app(PlanGuard::class)->assertCanAdd($this->tenantManager->getCompany()->loadMissing('plan'), 'users');
    }

    protected function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Branches the user may work in, and their default among them. The owner
     * works in every branch (no rows). Others need at least one: the ticked
     * ones, else their default, else the main branch.
     */
    protected function resolveBranches(array $validated): array
    {
        if ($validated['role'] === $this->registry->ownerRole()) {
            $validated['branch_ids'] = [];

            return $validated;
        }

        $ids = array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? [])));
        $default = ! empty($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        if ($ids === []) {
            $default ??= Branch::orderByDesc('is_main')->orderBy('id')->value('id');
            $ids = $default ? [$default] : [];
        }
        if ($default !== null && ! in_array($default, $ids, true)) {
            throw ValidationException::withMessages(['branch_id' => 'الفرع الافتراضي يجب أن يكون من الفروع المسموح بها للمستخدم.']);
        }

        $validated['branch_ids'] = $ids;
        $validated['branch_id'] = $default ?? ($ids[0] ?? null);

        return $validated;
    }
}

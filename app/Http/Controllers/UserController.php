<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\PermissionRegistry;
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

        $users = User::with(['branch', 'roles:id,name'])
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
            'password' => ['required', 'string', Password::min(8)],
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'role' => ['required', 'string', $this->companyRoleRule()],
            'status' => 'required|string|in:active,inactive',
        ]);

        $this->assertMayAssignRole($request, $validated['role']);
        $this->enforceUserLimit();

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
            ]);
            $user->save();

            $user->assignRole($validated['role']);

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
            'password' => ['nullable', 'string', Password::min(8)],
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'role' => ['required', 'string', $this->companyRoleRule()],
            'status' => 'required|string|in:active,inactive',
        ]);

        $this->assertMayManageUser($request, $user);
        $this->assertMayAssignRole($request, $validated['role']);

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

        AuditLog::record('user_deleted', $user, ['email' => $user->email]);
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
     * Only an owner may manage another owner's account.
     */
    protected function assertMayManageUser(Request $request, User $user): void
    {
        if ($user->hasRole($this->registry->ownerRole()) && ! $this->isPrivileged($request)) {
            abort(403, 'فقط مدير المنشأة يمكنه تعديل حساب مدير آخر.');
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
        $limit = $this->tenantManager->getCompany()?->plan?->max_users;

        if ($limit && User::where('company_id', $this->tenantManager->getTenantId())->count() >= $limit) {
            throw ValidationException::withMessages([
                'email' => "وصلت إلى الحد الأقصى للمستخدمين في باقتك ({$limit}). يرجى ترقية الباقة.",
            ]);
        }
    }

    protected function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

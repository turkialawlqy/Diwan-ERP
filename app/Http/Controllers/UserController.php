<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        $users = User::with(['branch', 'roles'])
            ->where('company_id', $companyId)
            ->where('is_super_admin', false)
            ->latest()
            ->paginate(15);

        $branches = Branch::where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['id', 'name_ar', 'name_en']);

        $roles = Role::whereNotIn('name', ['super_admin'])->get(['id', 'name']);

        $stats = [
            'total_users' => User::where('company_id', $companyId)->where('is_super_admin', false)->count(),
            'active_users' => User::where('company_id', $companyId)->where('status', 'active')->where('is_super_admin', false)->count(),
        ];

        return Inertia::render('Users/Index', [
            'users' => $users,
            'branches' => $branches,
            'roles' => $roles,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Emails are globally unique: login is by email and must map to one company.
                Rule::unique('users', 'email'),
            ],
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'role' => ['required', 'string', Rule::exists('roles', 'name')->whereNot('name', 'super_admin')],
            'status' => 'required|string|in:active,inactive',
        ]);

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

        return redirect()->route('users.index')
            ->with('success', 'تم إنشاء المستخدم بنجاح');
    }

    public function update(Request $request, User $user)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        if ($user->company_id !== $companyId || $user->is_super_admin) {
            abort(403, 'غير مصرح لك بتعديل هذا المستخدم.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => 'nullable|string|min:8',
            'phone' => 'nullable|string|max:20',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'role' => ['required', 'string', Rule::exists('roles', 'name')->whereNot('name', 'super_admin')],
            'status' => 'required|string|in:active,inactive',
        ]);

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

        return redirect()->route('users.index')
            ->with('success', 'تم تحديث بيانات المستخدم بنجاح');
    }

    public function destroy(User $user)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        if ($user->company_id !== $companyId || $user->is_super_admin) {
            abort(403, 'غير مصرح لك بحذف هذا المستخدم.');
        }

        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'لا يمكنك حذف حسابك الحالي أثناء تسجيل الدخول.']);
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'تم حذف المستخدم بنجاح');
    }
}

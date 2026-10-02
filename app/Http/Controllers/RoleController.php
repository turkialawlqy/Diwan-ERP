<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        $roles = Role::whereNotIn('name', ['super_admin'])
            ->get()
            ->map(function ($role) use ($companyId) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'users_count' => User::role($role->name)->where('company_id', $companyId)->count(),
                    'is_system' => in_array($role->name, ['admin', 'cashier', 'sales', 'accountant', 'employee']),
                ];
            });

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:roles,name',
        ]);

        Role::create([
            'name' => strtolower($validated['name']),
            'guard_name' => 'web',
        ]);

        return redirect()->route('roles.index')
            ->with('success', 'تم إنشاء الدور الجديد بنجاح');
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, ['admin', 'cashier', 'sales', 'accountant', 'employee', 'super_admin'])) {
            return back()->withErrors(['role' => 'لا يمكن حذف الأدوار الافتراضية للنظام.']);
        }

        $role->delete();

        return redirect()->route('roles.index')
            ->with('success', 'تم حذف الدور بنجاح');
    }
}

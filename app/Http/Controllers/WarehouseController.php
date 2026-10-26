<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Warehouse;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(): Response
    {
        $warehouses = Warehouse::with(['branch'])
            ->withCount('stocks')
            ->latest()
            ->get();

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        $stats = [
            'total_warehouses' => Warehouse::count(),
            'active_warehouses' => Warehouse::where('is_active', true)->count(),
            'main_warehouse' => Warehouse::where('is_main', true)->first()?->name ?? '—',
        ];

        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses' => $warehouses,
            'branches' => $branches,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'code' => 'required|string|max:50',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'is_main' => 'boolean',
        ]);

        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? auth()->user()?->company_id;

        // If marked as main, unset any other main warehouse for this company
        if (! empty($validated['is_main'])) {
            Warehouse::where('is_main', true)->update(['is_main' => false]);
        }

        Warehouse::create(array_merge($validated, [
            'company_id' => $companyId,
            'is_active' => true,
        ]));

        return redirect()->route('inventory.warehouses.index')
            ->with('success', 'تم إضافة المستودع بنجاح');
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasTenant() && $warehouse->company_id !== $tenantManager->getTenantId()) {
            abort(403, 'غير مصرح لك بتعديل هذا المستودع.');
        }

        $validated = $request->validate([
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'code' => 'required|string|max:50',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if (! empty($validated['is_main'])) {
            Warehouse::where('id', '!=', $warehouse->id)->where('is_main', true)->update(['is_main' => false]);
        }

        $warehouse->update($validated);

        return redirect()->route('inventory.warehouses.index')
            ->with('success', 'تم تحديث بيانات المستودع بنجاح');
    }

    public function destroy(Warehouse $warehouse)
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasTenant() && $warehouse->company_id !== $tenantManager->getTenantId()) {
            abort(403, 'غير مصرح لك بحذف هذا المستودع.');
        }

        if ($warehouse->is_main) {
            return back()->withErrors(['warehouse' => 'لا يمكن حذف المستودع الرئيسي للمنشأة.']);
        }

        if ($warehouse->stocks()->where('quantity', '>', 0)->exists()) {
            return back()->withErrors(['warehouse' => 'لا يمكن حذف المستودع لوجود أرصدة مخزنية به.']);
        }

        $warehouse->delete();

        return redirect()->route('inventory.warehouses.index')
            ->with('success', 'تم حذف المستودع بنجاح');
    }
}

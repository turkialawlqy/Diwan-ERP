<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\Accounting\DocumentPoster;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Supplier::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $suppliers = $query->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $stats = [
            'total_suppliers' => Supplier::count(),
            'total_payable' => Supplier::sum('current_balance'),
            'active_suppliers' => Supplier::where('status', 'active')->count(),
        ];

        return Inertia::render('Purchases/Suppliers/Index', [
            'suppliers' => $suppliers,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:30',
            'commercial_register' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:100',
            'address' => 'nullable|string',
            'bank_name' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric',
        ]);

        $companyId = app(TenantManager::class)->getTenantId()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $openingBal = (float) ($validated['opening_balance'] ?? 0);

        $supplier = DB::transaction(function () use ($validated, $companyId, $openingBal) {
            $supplier = Supplier::create([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'company_name' => $validated['company_name'] ?? null,
                'tax_number' => $validated['tax_number'] ?? null,
                'commercial_register' => $validated['commercial_register'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'bank_name' => $validated['bank_name'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'opening_balance' => $openingBal,
                'current_balance' => $openingBal,
                'status' => 'active',
            ]);

            // Opening payable (positive) or supplier advance (negative) in the ledger, so the
            // supplier list and the payables account agree from day one.
            app(DocumentPoster::class)->openingBalance(
                $supplier,
                ['role' => 'payables', 'side' => 'credit', 'party_type' => 'supplier', 'party_id' => $supplier->id],
                $openingBal,
                now(),
                'رصيد افتتاحي للمورد '.$supplier->name,
            );

            return $supplier;
        });

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'success' => true,
                'supplier' => $supplier,
                'message' => 'تمت إضافة المورد بنجاح',
            ], 201);
        }

        return back()->with('success', 'تمت إضافة المورد بنجاح');
    }

    /**
     * Update the specified supplier.
     */
    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:30',
            'commercial_register' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:100',
            'address' => 'nullable|string',
            'bank_name' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:50',
            'status' => 'required|in:active,inactive',
        ]);

        $supplier->update($validated);

        return back()->with('success', 'تم تحديث بيانات المورد بنجاح');
    }

    /**
     * Remove the specified supplier.
     */
    public function destroy(Supplier $supplier)
    {
        if ($supplier->purchaseInvoices()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف المورد لوجود فواتير مشتريات مرتبطة به');
        }

        $supplier->delete();

        return back()->with('success', 'تم حذف المورد بنجاح');
    }
}

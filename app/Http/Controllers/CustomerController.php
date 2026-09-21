<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Customer::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('tax_number', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20))
            ->withQueryString();

        $stats = [
            'total_customers' => Customer::count(),
            'active_customers' => Customer::where('status', 'active')->count(),
            'total_receivables' => (float) Customer::sum('current_balance'),
        ];

        return Inertia::render('Sales/Clients/Index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show the form for creating a new customer.
     */
    public function create()
    {
        return Inertia::render('Sales/Clients/Create');
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'commercial_registration' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric|min:0',
            'opening_balance' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        $openingBalance = (float) ($validated['opening_balance'] ?? 0);
        $validated['current_balance'] = $openingBalance;

        $customer = Customer::create($validated);

        return redirect()->route('sales.clients.index')->with('success', 'تمت إضافة العميل بنجاح');
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $client)
    {
        $client->load([
            'invoices' => fn ($q) => $q->latest()->take(10),
            'payments' => fn ($q) => $q->latest()->take(10),
            'quotations' => fn ($q) => $q->latest()->take(10),
        ]);

        return Inertia::render('Sales/Clients/Show', [
            'client' => $client,
        ]);
    }

    /**
     * Show the form for editing the specified customer.
     */
    public function edit(Customer $client)
    {
        return Inertia::render('Sales/Clients/Edit', [
            'client' => $client,
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, Customer $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'commercial_registration' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ]);

        $client->update($validated);

        return redirect()->route('sales.clients.index')->with('success', 'تم تحديث بيانات العميل بنجاح');
    }

    /**
     * Remove the specified customer.
     */
    public function destroy(Customer $client)
    {
        if ($client->invoices()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف العميل لوجود فواتير مرتبطة به');
        }

        $client->delete();

        return redirect()->route('sales.clients.index')->with('success', 'تم حذف العميل بنجاح');
    }
}

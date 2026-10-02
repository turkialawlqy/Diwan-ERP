<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Helper to get next customer code.
     */
    protected function getNextCustomerCode(): string
    {
        $count = Customer::count() + 1;
        return '1-' . str_pad($count, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $type = $request->input('customer_type');

        $query = Customer::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('tax_number', 'like', "%{$search}%")
                  ->orWhere('commercial_registration', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('customer_type', $type);
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
                'customer_type' => $type,
            ],
            'stats' => $stats,
            'nextCode' => $this->getNextCustomerCode(),
        ]);
    }

    /**
     * Show the form for creating a new customer.
     */
    public function create()
    {
        return Inertia::render('Sales/Clients/Create', [
            'nextCode' => $this->getNextCustomerCode(),
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_type' => 'nullable|in:individual,commercial',
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'commercial_registration' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'building_number' => 'nullable|string|max:50',
            'street_name' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'additional_details' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'opening_balance' => 'nullable|numeric',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string',
            'additional_contacts' => 'nullable|array',
            'additional_contacts.*.name' => 'nullable|string|max:255',
            'additional_contacts.*.phone' => 'nullable|string|max:50',
            'additional_contacts.*.email' => 'nullable|email|max:255',
            'additional_contacts.*.role' => 'nullable|string|max:100',
            'attachments' => 'nullable|array',
        ]);

        $validated['customer_type'] = $validated['customer_type'] ?? 'commercial';
        if (empty($validated['code'])) {
            $validated['code'] = $this->getNextCustomerCode();
        }

        $openingBalance = (float) ($validated['opening_balance'] ?? 0);
        $validated['opening_balance'] = $openingBalance;
        $validated['current_balance'] = $openingBalance;
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['country'] = $validated['country'] ?? 'المملكة العربية السعودية';

        // Set address summary if individual parts are present
        if (empty($validated['address']) && (!empty($validated['building_number']) || !empty($validated['street_name']))) {
            $parts = array_filter([
                $validated['building_number'] ? ('مبنى ' . $validated['building_number']) : null,
                $validated['street_name'],
                $validated['district'],
                $validated['city'],
            ]);
            $validated['address'] = implode(' - ', $parts);
        }

        $customer = Customer::create($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'customer' => $customer,
                'message' => 'تمت إضافة العميل بنجاح',
            ], 201);
        }

        return redirect()->route('sales.clients.index')->with('success', 'تمت إضافة العميل بنجاح');
    }

    /**
     * Upload customer attachment file.
     */
    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $path = $file->store('customers/attachments', 'public');
        $url = Storage::url($path);

        return response()->json([
            'success' => true,
            'file' => [
                'name' => $originalName,
                'url' => $url,
                'size' => $file->getSize(),
                'type' => $file->getClientMimeType(),
            ],
            'message' => 'تم رفع الملف بنجاح',
        ]);
    }

    /**
     * Display the specified customer.
     */
    public function show(Request $request, Customer $client)
    {
        $client->load([
            'invoices' => fn ($q) => $q->latest()->take(30),
            'payments' => fn ($q) => $q->latest()->take(30),
            'quotations' => fn ($q) => $q->latest()->take(30),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'client' => $client,
            ]);
        }

        return Inertia::render('Sales/Clients/Show', [
            'client' => $client,
        ]);
    }

    /**
     * Show statement for customer.
     */
    public function statement(Customer $client)
    {
        $client->load([
            'invoices' => fn ($q) => $q->orderBy('invoice_date', 'asc'),
            'payments' => fn ($q) => $q->orderBy('payment_date', 'asc'),
        ]);

        $rawTransactions = collect();

        // 1. Process Invoices (Debit) and their immediate checkout payments (Credit)
        foreach ($client->invoices as $inv) {
            $invDate = $inv->invoice_date ? $inv->invoice_date->format('Y-m-d') : ($inv->created_at ? $inv->created_at->format('Y-m-d') : now()->format('Y-m-d'));
            $invTotal = (float) ($inv->total ?? $inv->total_amount ?? 0);

            $rawTransactions->push([
                'date' => $invDate,
                'created_at' => $inv->created_at ? $inv->created_at->toIso8601String() : $invDate,
                'order_key' => 1,
                'type' => 'invoice',
                'type_ar' => 'فاتورة ضريبية',
                'reference' => $inv->invoice_number,
                'debit' => $invTotal,
                'credit' => 0.00,
                'notes' => $inv->notes ?: ('فاتورة مبيعات ' . $inv->invoice_number),
            ]);

            // Account for any immediate payments at checkout not recorded in customer payments table
            $linkedPaymentsSum = (float) $client->payments->where('invoice_id', $inv->id)->sum('amount');
            $immediatePaid = max(0, (float) $inv->paid_amount - $linkedPaymentsSum);

            if ($immediatePaid > 0) {
                $rawTransactions->push([
                    'date' => $invDate,
                    'created_at' => $inv->created_at ? $inv->created_at->toIso8601String() : $invDate,
                    'order_key' => 2,
                    'type' => 'payment',
                    'type_ar' => 'سداد فوري للفاتورة',
                    'reference' => 'سداد ' . $inv->invoice_number,
                    'debit' => 0.00,
                    'credit' => $immediatePaid,
                    'notes' => 'سداد فوري عند إصدار الفاتورة (' . ($inv->payment_method === 'cash' ? 'نقداً' : ($inv->payment_method === 'card' ? 'بطاقة/مدى' : 'تحويل')) . ')',
                ]);
            }
        }

        // 2. Process Customer Payments (Credit)
        foreach ($client->payments as $pmt) {
            $pmtDate = $pmt->payment_date ? $pmt->payment_date->format('Y-m-d') : ($pmt->created_at ? $pmt->created_at->format('Y-m-d') : now()->format('Y-m-d'));
            $rawTransactions->push([
                'date' => $pmtDate,
                'created_at' => $pmt->created_at ? $pmt->created_at->toIso8601String() : $pmtDate,
                'order_key' => 2,
                'type' => 'payment',
                'type_ar' => 'سند قبض',
                'reference' => $pmt->payment_number ?: ('PAY-' . $pmt->id),
                'debit' => 0.00,
                'credit' => (float) $pmt->amount,
                'notes' => $pmt->notes ?: ('سند قبض عبر ' . ($pmt->payment_method === 'cash' ? 'نقداً' : ($pmt->payment_method === 'card' ? 'بطاقة/مدى' : 'تحويل'))),
            ]);
        }

        // 3. Sort transactions chronologically
        $sorted = $rawTransactions->sortBy(function ($item) {
            return $item['date'] . '_' . $item['order_key'] . '_' . ($item['created_at'] ?? '');
        })->values();

        // 4. Calculate Progressive Running Balance
        $openingBalance = (float) ($client->opening_balance ?? 0);
        $runningBalance = $openingBalance;
        $finalTransactions = collect();

        // First date for opening balance
        $firstDate = $sorted->first()['date'] ?? ($client->created_at ? $client->created_at->format('Y-m-d') : now()->format('Y-m-d'));

        // Always show opening balance at the very top if non-zero or if no other transactions exist
        if ($openingBalance != 0 || $sorted->isEmpty()) {
            $finalTransactions->push([
                'date' => $firstDate,
                'type' => 'opening_balance',
                'type_ar' => 'رصيد افتتاحي',
                'reference' => 'افتتاحي',
                'debit' => $openingBalance > 0 ? $openingBalance : 0.00,
                'credit' => $openingBalance < 0 ? abs($openingBalance) : 0.00,
                'balance' => $runningBalance,
                'notes' => 'الرصيد الافتتاحي للعميل',
            ]);
        }

        foreach ($sorted as $item) {
            $runningBalance += ($item['debit'] - $item['credit']);
            $item['balance'] = round($runningBalance, 2);
            $finalTransactions->push($item);
        }

        return Inertia::render('Sales/Clients/Statement', [
            'client' => $client,
            'transactions' => $finalTransactions,
            'openingBalance' => $openingBalance,
            'closingBalance' => round($runningBalance, 2),
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
            'customer_type' => 'nullable|in:individual,commercial',
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'commercial_registration' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'building_number' => 'nullable|string|max:50',
            'street_name' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'additional_details' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
            'additional_contacts' => 'nullable|array',
            'additional_contacts.*.name' => 'nullable|string|max:255',
            'additional_contacts.*.phone' => 'nullable|string|max:50',
            'additional_contacts.*.email' => 'nullable|email|max:255',
            'additional_contacts.*.role' => 'nullable|string|max:100',
            'attachments' => 'nullable|array',
        ]);

        if (empty($validated['address']) && (!empty($validated['building_number']) || !empty($validated['street_name']))) {
            $parts = array_filter([
                $validated['building_number'] ? ('مبنى ' . $validated['building_number']) : null,
                $validated['street_name'],
                $validated['district'],
                $validated['city'],
            ]);
            $validated['address'] = implode(' - ', $parts);
        }

        $client->update($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'customer' => $client,
                'message' => 'تم تحديث بيانات العميل بنجاح',
            ]);
        }

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

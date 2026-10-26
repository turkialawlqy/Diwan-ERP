<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Rules\TaxNumber;
use App\Services\Accounting\DocumentPoster;
use App\Services\Sales\CustomerStatement;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Helper to get next customer code.
     */
    protected function getNextCustomerCode(): string
    {
        return $this->nextNumber('customer');
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
            ->paginate($this->perPage($request))
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
            'nextCode' => $this->peekNumber('customer'),
        ]);
    }

    /**
     * Show the form for creating a new customer.
     */
    public function create()
    {
        return Inertia::render('Sales/Clients/Create', [
            'nextCode' => $this->peekNumber('customer'),
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $this->normalizeNationalAddress($request);
        $validated = $request->validate([
            'customer_type' => 'nullable|in:individual,commercial',
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'tax_number' => ['nullable', 'string', 'max:50', new TaxNumber($request->input('country'))],
            'commercial_registration' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'building_number' => ['nullable', ...$this->nationalAddressNumber($request, 4)],
            'street_name' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => ['nullable', ...$this->nationalAddressNumber($request, 5)],
            'additional_details' => ['nullable', ...$this->nationalAddressNumber($request, 4)],
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
            'attachments' => 'nullable|array|max:50',
            'attachments.*.name' => 'nullable|string|max:255',
            // Only links to files we serve ourselves (private /files/ or legacy uploads).
            'attachments.*.url' => ['required', 'string', 'max:2048', 'regex:#^/(files/|storage/customers/attachments/)#'],
            'attachments.*.size' => 'nullable|integer',
            'attachments.*.type' => 'nullable|string|max:150',
        ]);

        $validated['customer_type'] = $validated['customer_type'] ?? 'commercial';
        if (empty($validated['code'])) {
            $validated['code'] = $this->getNextCustomerCode();
        }

        $openingBalance = (float) ($validated['opening_balance'] ?? 0);
        $validated['opening_balance'] = $openingBalance;
        $validated['current_balance'] = $openingBalance;
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['country'] = $validated['country'] ?? TaxProfile::current()->country['name'];

        // Set address summary if individual parts are present
        if (empty($validated['address']) && (! empty($validated['building_number']) || ! empty($validated['street_name']))) {
            $parts = array_filter([
                $validated['building_number'] ? ('مبنى '.$validated['building_number']) : null,
                $validated['street_name'],
                $validated['district'],
                $validated['city'],
            ]);
            $validated['address'] = implode(' - ', $parts);
        }

        $customer = DB::transaction(function () use ($validated) {
            $customer = Customer::create($validated);

            // Opening receivable (positive) or advance (negative) in the ledger.
            app(DocumentPoster::class)->openingBalance(
                $customer,
                ['role' => 'receivables', 'side' => 'debit', 'party_type' => 'customer', 'party_id' => $customer->id],
                (float) $validated['opening_balance'],
                now(),
                'رصيد افتتاحي للعميل '.$customer->name,
            );

            return $customer;
        });

        if ($this->wantsJsonResponse($request)) {
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
            'file' => 'required|file|max:10240|mimes:'.FileController::DOCUMENT_MIMES,
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $url = FileController::storeForTenant($file, 'customers/attachments')['url'];

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

        if ($this->wantsJsonResponse($request)) {
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
    public function statement(Customer $client, CustomerStatement $statements)
    {
        $statement = $statements->build($client);

        return Inertia::render('Sales/Clients/Statement', [
            'client' => $client->setRelations([]),
            'transactions' => $statement['transactions'],
            'openingBalance' => $statement['opening'],
            'closingBalance' => $statement['closing'],
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
        $this->normalizeNationalAddress($request);
        $validated = $request->validate([
            'customer_type' => 'nullable|in:individual,commercial',
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'tax_number' => ['nullable', 'string', 'max:50', new TaxNumber($request->input('country'))],
            'commercial_registration' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'building_number' => ['nullable', ...$this->nationalAddressNumber($request, 4)],
            'street_name' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => ['nullable', ...$this->nationalAddressNumber($request, 5)],
            'additional_details' => ['nullable', ...$this->nationalAddressNumber($request, 4)],
            'country' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
            'additional_contacts' => 'nullable|array',
            'additional_contacts.*.name' => 'nullable|string|max:255',
            'additional_contacts.*.phone' => 'nullable|string|max:50',
            'additional_contacts.*.email' => 'nullable|email|max:255',
            'additional_contacts.*.role' => 'nullable|string|max:100',
            'attachments' => 'nullable|array|max:50',
            'attachments.*.name' => 'nullable|string|max:255',
            // Only links to files we serve ourselves (private /files/ or legacy uploads).
            'attachments.*.url' => ['required', 'string', 'max:2048', 'regex:#^/(files/|storage/customers/attachments/)#'],
            'attachments.*.size' => 'nullable|integer',
            'attachments.*.type' => 'nullable|string|max:150',
        ]);

        if (empty($validated['address']) && (! empty($validated['building_number']) || ! empty($validated['street_name']))) {
            $parts = array_filter([
                $validated['building_number'] ? ('مبنى '.$validated['building_number']) : null,
                $validated['street_name'],
                $validated['district'],
                $validated['city'],
            ]);
            $validated['address'] = implode(' - ', $parts);
        }

        $client->update($validated);

        if ($this->wantsJsonResponse($request)) {
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

    /**
     * National address as ZATCA needs it: the building number on its own.
     * A street typed as "4002, King Fahd Road" (the form once had one field
     * for both) gives its leading four digits to the building number;
     * Arabic-Indic digits are read as digits.
     */
    /**
     * The Saudi national address has fixed-length numbers (building and
     * additional number 4 digits, postal code 5). Customers in other
     * countries keep their own formats.
     */
    protected function nationalAddressNumber(Request $request, int $digits): array
    {
        $country = TaxProfile::countryCodeFromName($request->input('country'), TaxProfile::current()->countryCode);

        return $country === 'SA' ? ['regex:/^\d{'.$digits.'}$/'] : ['string', 'max:20'];
    }

    protected function normalizeNationalAddress(Request $request): void
    {
        $digits = fn ($v) => is_string($v) ? trim(strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])) : $v;
        $values = array_map($digits, $request->only(['building_number', 'street_name', 'postal_code', 'additional_details', 'tax_number']));
        if (isset($values['tax_number']) && is_string($values['tax_number'])) {
            $values['tax_number'] = preg_replace('/[\s\-]+/u', '', $values['tax_number']);
        }
        [$building, $street] = self::splitBuildingNumber($values['building_number'] ?? null, $values['street_name'] ?? null);
        $request->merge(array_merge($values, ['building_number' => $building, 'street_name' => $street]));
    }

    /** [building number, street] from a street that starts with the 4-digit building number. */
    public static function splitBuildingNumber(?string $building, ?string $street): array
    {
        if (blank($building) && $street && preg_match('/^\s*(\d{4})\s*[,،\-\s]\s*(.+)$/u', $street, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [$building ?: null, $street];
    }
}

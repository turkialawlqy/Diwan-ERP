<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Product;
use App\Rules\TaxNumber;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST API v1 (Sanctum personal access tokens, scoped to the token owner's
 * company; every route is authorised against the token's abilities).
 */
class ApiController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email']),
            'company' => app(TenantManager::class)->getCompany()?->only(['id', 'name', 'subdomain', 'tax_number']),
            'abilities' => $token?->abilities ?? [],
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('search', ''));

        return response()->json(Customer::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('tax_number', $q)->orWhere('phone', 'like', "{$q}%")->orWhere('code', $q)))
            ->orderBy('id')
            ->paginate($this->perPage($request), ['id', 'code', 'name', 'company_name', 'tax_number', 'phone', 'email', 'city', 'credit_limit', 'current_balance', 'status', 'updated_at']));
    }

    public function customer(Customer $customer): JsonResponse
    {
        return response()->json(['data' => $customer->only(['id', 'code', 'name', 'company_name', 'tax_number', 'commercial_registration', 'phone', 'email',
            'street_name', 'building_number', 'district', 'city', 'postal_code', 'credit_limit', 'current_balance', 'status'])]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'customer_type' => 'nullable|in:individual,commercial,government',
            'tax_number' => ['nullable', 'string', 'max:50', new TaxNumber($request->input('country'))],
            'commercial_registration' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'street_name' => 'nullable|string|max:255',
            'building_number' => 'nullable|string|max:10',
            'district' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        $customer = Customer::create($validated + ['customer_type' => 'commercial', 'status' => 'active', 'code' => $this->nextNumber('customer')]);

        return response()->json(['data' => $customer->only(['id', 'code', 'name'])], 201);
    }

    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('search', ''));

        return response()->json(Product::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name_ar', 'like', "%{$q}%")->orWhere('name_en', 'like', "%{$q}%")->orWhere('sku', $q)->orWhere('barcode', $q)))
            ->where('is_active', true)->orderBy('id')
            ->paginate($this->perPage($request), ['id', 'sku', 'barcode', 'name_ar', 'name_en', 'selling_price', 'tax_rate', 'type', 'track_inventory', 'updated_at']));
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->only(['id', 'sku', 'barcode', 'name_ar', 'name_en', 'selling_price', 'tax_rate', 'vat_category', 'vat_exemption_code', 'type', 'track_inventory']) + [
            'stock' => InventoryStock::with('warehouse:id,name_ar')->where('product_id', $product->id)->get()
                ->map(fn ($s) => ['warehouse_id' => $s->warehouse_id, 'warehouse' => $s->warehouse?->name_ar, 'quantity' => (float) $s->quantity]),
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $filters = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'customer_id' => 'nullable|integer', 'payment_status' => 'nullable|in:paid,partially_paid,unpaid']);

        return response()->json(Invoice::query()
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->orderByDesc('id')
            ->paginate($this->perPage($request), ['id', 'invoice_number', 'invoice_type', 'customer_id', 'invoice_date', 'subtotal', 'tax_amount', 'total', 'paid_amount', 'remaining_amount', 'payment_status', 'zatca_status', 'zatca_uuid']));
    }

    public function invoice(Invoice $invoice): JsonResponse
    {
        $invoice->load('items:id,invoice_id,product_id,item_name,quantity,unit_price,discount,tax_rate,tax_amount,total');

        return response()->json(['data' => $invoice->only(['id', 'invoice_number', 'invoice_type', 'customer_id', 'invoice_date', 'subtotal', 'discount_amount', 'tax_amount',
            'total', 'paid_amount', 'remaining_amount', 'payment_status', 'zatca_status', 'zatca_uuid', 'zatca_qr', 'zatca_hash']) + ['items' => $invoice->items]]);
    }

    public function storeInvoice(Request $request, InvoiceService $invoices): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'invoice_date' => 'required|date|before_or_equal:today',
            'invoice_type' => 'nullable|in:standard,simplified',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'payment_method' => 'nullable|in:cash,card,bank_transfer,credit',
            'payment_status' => 'nullable|in:paid,partially_paid,unpaid',
            'paid_amount' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'nullable|tenant_exists:products,id',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.vat_category' => 'nullable|in:S,Z,E,O',
            'items.*.vat_exemption_code' => 'nullable|string|max:30',
        ]);

        $invoice = $invoices->create($validated, $validated['items']);

        return $this->invoice($invoice)->setStatusCode(201);
    }
}

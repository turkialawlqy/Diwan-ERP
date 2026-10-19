<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * Top-bar search: a few matches per kind of record, only for the kinds the
 * user may view. Tenant (and branch) scopes apply through the models.
 */
class SearchController extends Controller
{
    public const PER_GROUP = 5;

    public function __invoke(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $user = $request->user();
        $groups = [];

        $add = function (string $key, string $label, string $permission, callable $query) use (&$groups, $user) {
            if (! $user->can($permission)) {
                return;
            }
            $items = $query()->take(self::PER_GROUP)->get()->all();
            if ($items) {
                $groups[] = ['key' => $key, 'label' => $label, 'items' => $items];
            }
        };

        $add('customers', 'العملاء', 'sales.clients.view', fn () => Customer::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('tax_number', 'like', $like))
            ->orderBy('name')->select('id', 'name', 'code', 'phone'));

        $add('suppliers', 'الموردون', 'purchases.suppliers.view', fn () => Supplier::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('tax_number', 'like', $like))
            ->orderBy('name')->select('id', 'name', 'phone'));

        $add('products', 'الأصناف', 'inventory.products.view', fn () => Product::query()
            ->where(fn ($q) => $q->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', $term))
            ->orderBy('name_ar')->select('id', 'name_ar', 'sku'));

        $add('invoices', 'فواتير المبيعات', 'sales.invoices.view', fn () => Invoice::query()->with('customer:id,name')
            ->where('invoice_number', 'like', $like)->latest('id')->select('id', 'invoice_number', 'customer_id', 'total', 'invoice_date'));

        $add('purchase_invoices', 'فواتير المشتريات', 'purchases.invoices.view', fn () => PurchaseInvoice::query()->with('supplier:id,name')
            ->where(fn ($q) => $q->where('invoice_number', 'like', $like)->orWhere('supplier_invoice_number', 'like', $like))
            ->latest('id')->select('id', 'invoice_number', 'supplier_invoice_number', 'supplier_id', 'total', 'invoice_date'));

        $add('employees', 'الموظفون', 'hr.employees.view', fn () => Employee::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_number', 'like', $like)->orWhere('national_id', 'like', $like))
            ->orderBy('name')->select('id', 'name', 'employee_number', 'job_title'));

        return response()->json(['groups' => array_map(fn ($g) => ['items' => array_map(fn ($row) => $this->present($g['key'], $row), $g['items'])] + $g, $groups)]);
    }

    /** Title, subtitle and link of one match. */
    protected function present(string $kind, $row): array
    {
        return match ($kind) {
            'customers' => ['title' => $row->name, 'subtitle' => trim($row->code.' '.$row->phone), 'url' => route('sales.clients.show', $row->id)],
            'suppliers' => ['title' => $row->name, 'subtitle' => (string) $row->phone, 'url' => route('purchases.suppliers.index', ['search' => $row->name])],
            'products' => ['title' => $row->name_ar, 'subtitle' => (string) $row->sku, 'url' => route('inventory.products.show', $row->id)],
            'invoices' => ['title' => $row->invoice_number, 'subtitle' => trim(($row->customer?->name ?? '').' · '.number_format((float) $row->total, 2), ' ·'), 'url' => route('sales.invoices.show', $row->id)],
            'purchase_invoices' => ['title' => $row->invoice_number, 'subtitle' => trim(($row->supplier?->name ?? '').' · '.($row->supplier_invoice_number ?? ''), ' ·'), 'url' => route('purchases.invoices.show', $row->id)],
            'employees' => ['title' => $row->name, 'subtitle' => trim($row->employee_number.' '.$row->job_title), 'url' => route('hr.employees.show', $row->id)],
        };
    }
}

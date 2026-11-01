<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Revenue;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Http\Request;

/**
 * Global search palette: a few matches per kind of record, only for the kinds the
 * user may view; `kind` narrows it to one kind (more rows). Tenant (and branch)
 * scopes apply through the models. To make a new record searchable, add it to kinds().
 */
class SearchController extends Controller
{
    public const PER_GROUP = 5;

    public const PER_KIND = 20;

    public function __invoke(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $kinds = $this->kinds();
        $only = $request->query('kind');
        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => [], 'kinds' => $this->visibleKinds($request, $kinds)]);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $user = $request->user();
        $groups = [];

        foreach ($kinds as $key => $kind) {
            if (($only && $only !== $key) || ! $user->can($kind['permission']) || $this->moduleOff($kind['permission'])) {
                continue;
            }
            $rows = $kind['query']($like, $term)->take($only ? self::PER_KIND : self::PER_GROUP)->get();
            if ($rows->isNotEmpty()) {
                $groups[] = ['key' => $key, 'label' => $kind['label'], 'items' => $rows->map(fn ($row) => $kind['present']($row))->all()];
            }
        }

        return response()->json(['groups' => $groups, 'kinds' => $this->visibleKinds($request, $kinds)]);
    }

    /** @return array<int, array{key: string, label: string}> the kinds the user may search (the palette's chips) */
    protected function visibleKinds(Request $request, array $kinds): array
    {
        return collect($kinds)->filter(fn ($k) => $request->user()->can($k['permission']) && ! $this->moduleOff($k['permission']))
            ->map(fn ($k, $key) => ['key' => $key, 'label' => $k['label']])->values()->all();
    }

    /** key => label, permission, query($like, $term), present($row) → title, code, subtitle, url */
    /** Permission names share the route prefixes of their module (hr., projects. ...). */
    protected function moduleOff(string $permission): bool
    {
        $company = app(TenantManager::class)->getCompany();

        return $company && app(PlanGuard::class)->unavailable($company, $permission);
    }

    protected function kinds(): array
    {
        return [
            'customers' => [
                'label' => 'العملاء', 'permission' => 'sales.clients.view',
                'query' => fn ($like) => Customer::query()
                    ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('tax_number', 'like', $like))
                    ->orderBy('name')->select('id', 'name', 'code', 'phone'),
                'present' => fn ($r) => ['title' => $r->name, 'code' => (string) $r->code, 'subtitle' => (string) $r->phone, 'url' => route('sales.clients.show', $r->id)],
            ],
            'suppliers' => [
                'label' => 'الموردون', 'permission' => 'purchases.suppliers.view',
                'query' => fn ($like) => Supplier::query()
                    ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('tax_number', 'like', $like))
                    ->orderBy('name')->select('id', 'name', 'phone'),
                'present' => fn ($r) => ['title' => $r->name, 'code' => '', 'subtitle' => (string) $r->phone, 'url' => route('purchases.suppliers.index', ['search' => $r->name])],
            ],
            'products' => [
                'label' => 'الأصناف', 'permission' => 'inventory.products.view',
                'query' => fn ($like, $term) => Product::query()
                    ->where(fn ($q) => $q->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', $term))
                    ->orderBy('name_ar')->select('id', 'name_ar', 'sku'),
                'present' => fn ($r) => ['title' => $r->name_ar, 'code' => (string) $r->sku, 'subtitle' => '', 'url' => route('inventory.products.show', $r->id)],
            ],
            'invoices' => [
                'label' => 'فواتير المبيعات', 'permission' => 'sales.invoices.view',
                'query' => fn ($like) => Invoice::query()->with('customer:id,name')
                    ->where('invoice_number', 'like', $like)->latest('id')->select('id', 'invoice_number', 'customer_id', 'total', 'invoice_date'),
                'present' => fn ($r) => ['title' => $r->customer?->name ?? $r->invoice_number, 'code' => $r->invoice_number, 'subtitle' => number_format((float) $r->total, 2), 'url' => route('sales.invoices.show', $r->id)],
            ],
            'purchase_invoices' => [
                'label' => 'فواتير المشتريات', 'permission' => 'purchases.invoices.view',
                'query' => fn ($like) => PurchaseInvoice::query()->with('supplier:id,name')
                    ->where(fn ($q) => $q->where('invoice_number', 'like', $like)->orWhere('supplier_invoice_number', 'like', $like))
                    ->latest('id')->select('id', 'invoice_number', 'supplier_invoice_number', 'supplier_id', 'total', 'invoice_date'),
                'present' => fn ($r) => ['title' => $r->supplier?->name ?? $r->invoice_number, 'code' => $r->invoice_number, 'subtitle' => trim(($r->supplier_invoice_number ?? '').' '.number_format((float) $r->total, 2)), 'url' => route('purchases.invoices.show', $r->id)],
            ],
            'quotations' => [
                'label' => 'عروض الأسعار', 'permission' => 'sales.quotations.view',
                'query' => fn ($like) => Quotation::query()->with('customer:id,name')->where('quotation_number', 'like', $like)->latest('id')->select('id', 'quotation_number', 'customer_id', 'total'),
                'present' => fn ($r) => ['title' => $r->customer?->name ?? $r->quotation_number, 'code' => $r->quotation_number, 'subtitle' => number_format((float) $r->total, 2), 'url' => route('sales.quotations.show', $r->id)],
            ],
            'sales_orders' => [
                'label' => 'أوامر البيع', 'permission' => 'sales.orders.view',
                'query' => fn ($like) => SalesOrder::query()->with('customer:id,name')->where('order_number', 'like', $like)->latest('id')->select('id', 'order_number', 'customer_id', 'total'),
                'present' => fn ($r) => ['title' => $r->customer?->name ?? $r->order_number, 'code' => $r->order_number, 'subtitle' => number_format((float) $r->total, 2), 'url' => route('sales.orders.show', $r->id)],
            ],
            'purchase_orders' => [
                'label' => 'أوامر الشراء', 'permission' => 'purchases.orders.view',
                'query' => fn ($like) => PurchaseOrder::query()->with('supplier:id,name')->where('order_number', 'like', $like)->latest('id')->select('id', 'order_number', 'supplier_id', 'total'),
                'present' => fn ($r) => ['title' => $r->supplier?->name ?? $r->order_number, 'code' => $r->order_number, 'subtitle' => number_format((float) $r->total, 2), 'url' => route('purchases.orders.show', $r->id)],
            ],
            'journals' => [
                'label' => 'القيود اليومية', 'permission' => 'accounting.journals.view',
                'query' => fn ($like) => JournalEntry::query()
                    ->where(fn ($q) => $q->where('entry_number', 'like', $like)->orWhere('description', 'like', $like)->orWhere('reference', 'like', $like))
                    ->latest('id')->select('id', 'entry_number', 'description', 'reference'),
                'present' => fn ($r) => ['title' => $r->description, 'code' => $r->entry_number, 'subtitle' => (string) $r->reference, 'url' => route('accounting.journals.show', $r->id)],
            ],
            'accounts' => [
                'label' => 'دليل الحسابات', 'permission' => 'accounting.accounts.view',
                'query' => fn ($like) => Account::query()->where(fn ($q) => $q->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))
                    ->orderBy('code')->select('id', 'code', 'name_ar'),
                'present' => fn ($r) => ['title' => $r->name_ar, 'code' => $r->code, 'subtitle' => '', 'url' => route('accounting.accounts.edit', $r->id)],
            ],
            'expenses' => [
                'label' => 'سندات الصرف', 'permission' => 'finance.expenses.view',
                'query' => fn ($like) => Expense::query()->where(fn ($q) => $q->where('expense_number', 'like', $like)->orWhere('title', 'like', $like))
                    ->latest('id')->select('id', 'expense_number', 'title', 'amount'),
                'present' => fn ($r) => ['title' => $r->title, 'code' => (string) $r->expense_number, 'subtitle' => number_format((float) $r->amount, 2), 'url' => route('finance.expenses.index', ['search' => $r->expense_number])],
            ],
            'revenues' => [
                'label' => 'سندات القبض', 'permission' => 'finance.revenues.view',
                'query' => fn ($like) => Revenue::query()->where(fn ($q) => $q->where('revenue_number', 'like', $like)->orWhere('title', 'like', $like))
                    ->latest('id')->select('id', 'revenue_number', 'title', 'amount'),
                'present' => fn ($r) => ['title' => $r->title, 'code' => (string) $r->revenue_number, 'subtitle' => number_format((float) $r->amount, 2), 'url' => route('finance.revenues.index', ['search' => $r->revenue_number])],
            ],
            'projects' => [
                'label' => 'المشاريع', 'permission' => 'projects.projects.view',
                'query' => fn ($like) => Project::query()->where(fn ($q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('contract_number', 'like', $like))
                    ->orderBy('name')->select('id', 'code', 'name'),
                'present' => fn ($r) => ['title' => $r->name, 'code' => $r->code, 'subtitle' => '', 'url' => route('projects.show', $r->id)],
            ],
            'employees' => [
                'label' => 'الموظفون', 'permission' => 'hr.employees.view',
                'query' => fn ($like) => Employee::query()
                    ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_number', 'like', $like)->orWhere('national_id', 'like', $like))
                    ->orderBy('name')->select('id', 'name', 'employee_number', 'job_title'),
                'present' => fn ($r) => ['title' => $r->name, 'code' => (string) $r->employee_number, 'subtitle' => (string) $r->job_title, 'url' => route('hr.employees.show', $r->id)],
            ],
        ];
    }
}

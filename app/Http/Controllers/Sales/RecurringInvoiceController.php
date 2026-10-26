<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Rules\AllowedBranch;
use App\Services\Accounting\ExchangeRates;
use App\Services\Sales\RecurringInvoiceRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RecurringInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $templates = RecurringInvoice::with(['customer:id,name,email', 'runs' => fn ($q) => $q->latest('period_date')->limit(12)->with('invoice:id,invoice_number,total,currency_code,payment_status')])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('next_run_date')
            ->paginate($this->perPage($request));
        $templates->getCollection()->each(fn ($t) => $t->setAttribute('estimated_total', $t->estimatedTotal()));

        // "Make recurring" from an existing invoice prefills the form.
        $prefill = null;
        if ($request->filled('from_invoice')) {
            $invoice = Invoice::with(['items', 'customer:id,name,email,tax_number'])->findOrFail($request->integer('from_invoice'));
            $prefill = [
                'customer_id' => $invoice->customer_id,
                'customer' => $invoice->customer,
                'name' => 'فاتورة دورية — '.$invoice->customer?->name,
                'currency_code' => $invoice->currency_code ?? '',
                'invoice_type' => $invoice->invoice_type,
                'lines' => $invoice->items->map(fn ($i) => ['product_id' => $i->product_id, 'item_name' => $i->item_name, 'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price, 'discount' => (float) $i->discount, 'tax_rate' => (float) $i->tax_rate])->values(),
            ];
        }

        return Inertia::render('Sales/Recurring/Index', ['templates' => $templates, 'prefill' => $prefill]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        RecurringInvoice::create($data + ['next_run_date' => $data['start_date'], 'created_by' => auth()->id(), 'status' => 'active']);

        return back()->with('success', 'تم إنشاء الفاتورة الدورية');
    }

    public function update(Request $request, RecurringInvoice $recurring)
    {
        $data = $this->validated($request);
        // A new start date before anything was issued moves the first issue.
        if ($recurring->generated_count === 0) {
            $data['next_run_date'] = $data['start_date'];
        }
        $recurring->update($data);

        return back()->with('success', 'تم تحديث الفاتورة الدورية');
    }

    public function toggle(RecurringInvoice $recurring)
    {
        abort_if($recurring->status === 'ended', 422, 'انتهت هذه الفاتورة الدورية.');
        $recurring->update(['status' => $recurring->status === 'active' ? 'paused' : 'active']);

        return back()->with('success', $recurring->status === 'active' ? 'تم استئناف الإصدار' : 'تم إيقاف الإصدار مؤقتاً');
    }

    /** Issues any periods that are already due (instead of waiting for the scheduler). */
    public function run(RecurringInvoice $recurring, RecurringInvoiceRunner $runner)
    {
        $count = $runner->runTemplate($recurring);
        $recurring->refresh();

        return back()->with($recurring->last_error ? 'error' : 'success', $recurring->last_error
            ? 'تعذّر الإصدار: '.$recurring->last_error
            : ($count ? "تم إصدار {$count} فاتورة" : 'لا توجد فترات مستحقة حتى اليوم؛ الإصدار التالي '.$recurring->next_run_date->toDateString()));
    }

    public function destroy(RecurringInvoice $recurring)
    {
        $recurring->delete();

        return back()->with('success', 'تم حذف الفاتورة الدورية (الفواتير الصادرة تبقى كما هي)');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'customer_id' => 'required|tenant_exists:customers,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'frequency' => 'required|in:monthly,quarterly,yearly',
            'interval' => 'nullable|integer|min:1|max:12',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'max_count' => 'nullable|integer|min:1|max:600',
            'due_days' => 'nullable|integer|min:0|max:365',
            'invoice_type' => 'nullable|in:standard,simplified',
            'auto_send' => 'boolean',
            'send_to' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
            'currency_code' => ExchangeRates::rules()['currency_code'],
            'lines' => 'required|array|min:1|max:100',
            'lines.*.product_id' => 'nullable|tenant_exists:products,id',
            'lines.*.item_name' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ], [], ['name' => 'اسم العقد', 'frequency' => 'التكرار', 'start_date' => 'تاريخ أول إصدار', 'send_to' => 'بريد الإرسال']);

        // Recurring billing is for services: issuing stock months ahead
        // without a delivery would desynchronise the warehouse.
        $stockItems = Product::whereIn('id', collect($data['lines'])->pluck('product_id')->filter())->get()->filter->isStockItem();
        if ($stockItems->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'الفواتير الدورية للخدمات فقط؛ الصنف «'.$stockItems->first()->name_ar.'» صنف مخزني.']);
        }
        if (! app(ExchangeRates::class)->isBase($data['currency_code'] ?? null)) {
            app(ExchangeRates::class)->resolve($data['currency_code'], null, $data['start_date']);
        } else {
            $data['currency_code'] = null;
        }

        $data['interval'] = $data['interval'] ?? 1;
        $data['invoice_type'] = $data['invoice_type'] ?? 'standard';
        $data['lines'] = array_map(fn ($l) => array_intersect_key($l, array_flip(['product_id', 'item_name', 'quantity', 'unit_price', 'discount', 'tax_rate'])), $data['lines']);
        $data['start_date'] = Carbon::parse($data['start_date'])->toDateString();

        return $data;
    }
}

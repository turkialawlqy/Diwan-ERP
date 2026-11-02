<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileController;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Models\WorkType;
use App\Services\WorkOrders\PartsService;
use App\Services\WorkOrders\TimeTracker;
use App\Services\WorkOrders\WorkOrderBilling;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Office side of work orders: list, dispatch board, form, order page and its actions. */
class WorkOrderController extends Controller
{
    public function __construct(
        protected WorkOrderService $orders,
        protected TimeTracker $time,
        protected PartsService $parts,
        protected WorkOrderBilling $billing,
    ) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => 'nullable|string', 'technician' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date', 'search' => 'nullable|string|max:100']);

        $orders = WorkOrder::with(['customer:id,name', 'lead:id,name', 'workType:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['technician'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('assigned_to', $t)->orWhereHas('crew', fn ($c) => $c->whereKey($t))))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_start', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_start', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('WorkOrders/Index', [
            'orders' => $orders,
            'filters' => ['status' => $f['status'] ?? '', 'technician' => $f['technician'] ?? '', 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? '', 'search' => $f['search'] ?? ''],
            'statuses' => WorkOrder::STATUSES,
            'priorities' => WorkOrder::PRIORITIES,
            'technicians' => Employee::orderBy('name')->limit(500)->get(['id', 'name']),
            'stats' => [
                'open' => WorkOrder::whereIn('status', WorkOrder::OPEN)->count(),
                'in_progress' => WorkOrder::where('status', 'in_progress')->count(),
                'to_invoice' => WorkOrder::where('status', 'done')->whereNull('invoice_id')->count(),
            ],
        ]);
    }

    /** One day: a column per technician and the unassigned orders. */
    public function board(Request $request): Response
    {
        $day = Carbon::parse($request->validate(['date' => 'nullable|date'])['date'] ?? today());
        $orders = WorkOrder::with(['customer:id,name', 'workType:id,name'])->whereIn('status', ['new', 'scheduled', 'in_progress', 'on_hold'])
            ->where(fn ($q) => $q->whereDate('scheduled_start', $day)->orWhereNull('scheduled_start'))
            ->orderBy('scheduled_start')->get(['id', 'number', 'title', 'customer_id', 'work_type_id', 'status', 'priority', 'assigned_to', 'scheduled_start', 'scheduled_end']);

        return Inertia::render('WorkOrders/Board', [
            'date' => $day->toDateString(),
            'technicians' => Employee::whereIn('id', $orders->pluck('assigned_to')->filter()->unique())->orderBy('name')->get(['id', 'name']),
            'orders' => $orders,
            'statuses' => WorkOrder::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = [];
        if ($request->filled('ticket') && ($t = Ticket::find($request->integer('ticket')))) {
            $prefill = ['ticket_id' => $t->id, 'customer_id' => $t->customer_id, 'title' => $t->subject, 'description' => $t->description];
        }
        if ($request->filled('booking') && ($b = Booking::with('service:id,name')->find($request->integer('booking')))) {
            $prefill = ['booking_id' => $b->id, 'customer_id' => $b->customer_id, 'title' => $b->service?->name, 'scheduled_start' => $b->starts_at->format('Y-m-d H:i'), 'scheduled_end' => $b->ends_at->format('Y-m-d H:i')];
        }
        if ($request->filled('customer')) {
            $prefill['customer_id'] = $request->integer('customer');
        }

        return Inertia::render('WorkOrders/Form', ['order' => null, 'prefill' => $prefill] + $this->lookups());
    }

    public function edit(WorkOrder $order): Response
    {
        abort_unless($order->isOpen(), 404);
        $order->load(['tasks', 'crew:id', 'customer:id,name']);

        return Inertia::render('WorkOrders/Form', ['order' => $order->toArray() + ['crew_ids' => $order->crew->pluck('id')->all()], 'prefill' => []] + $this->lookups());
    }

    public function store(Request $request)
    {
        $order = $this->orders->create($this->validated($request), $request->user());

        return redirect()->route('work-orders.show', $order->id)->with('success', 'تم إنشاء أمر العمل '.$order->number);
    }

    public function update(Request $request, WorkOrder $order)
    {
        $this->orders->update($order, $this->validated($request));

        return redirect()->route('work-orders.show', $order->id)->with('success', 'تم حفظ أمر العمل');
    }

    public function destroy(WorkOrder $order)
    {
        if ($order->status !== 'new' || $order->timeEntries()->exists() || $order->parts()->exists()) {
            throw ValidationException::withMessages(['status' => 'يُحذف الأمر الجديد الذي لم يُسجَّل عليه وقت أو قطع فقط؛ ألغِه بدل الحذف.']);
        }
        $order->tasks()->delete();
        $order->delete();

        return redirect()->route('work-orders.index')->with('success', 'تم حذف أمر العمل');
    }

    public function show(WorkOrder $order): Response
    {
        $order->load(['customer:id,name,phone', 'workType', 'lead:id,name', 'crew:id,name', 'tasks', 'timeEntries.employee:id,name', 'parts.product:id,name_ar,sku', 'attachments', 'invoice:id,invoice_number,total,remaining_amount', 'ticket:id,number,subject']);
        $preview = $order->status === 'done' || $order->status === 'invoiced' || $order->isOpen() ? $this->billing->preview($order) : null;

        return Inertia::render('WorkOrders/Show', [
            'order' => $order->toArray() + ['signature_url' => $order->customer_signature_path ? $this->fileUrl($order->customer_signature_path) : null],
            'attachments' => $order->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'url' => $this->fileUrl($a->path)])->values(),
            'totals' => $this->time->totals($order),
            'preview' => $preview ? ['revenue' => $preview['revenue'], 'cost' => $preview['cost'], 'margin' => $preview['margin'], 'lines' => $preview['lines']] : null,
            'technicians' => Employee::orderBy('name')->limit(500)->get(['id', 'name']),
            'products' => $order->isOpen() ? Product::where('is_active', true)->orderBy('name_ar')->limit(500)->get(['id', 'name_ar', 'sku']) : [],
            'statuses' => WorkOrder::STATUSES,
            'priorities' => WorkOrder::PRIORITIES,
        ]);
    }

    public function hold(WorkOrder $order)
    {
        $this->orders->hold($order);

        return back()->with('success', 'عُلّق الأمر');
    }

    public function complete(Request $request, WorkOrder $order)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:5000']);
        $this->orders->complete($order, $data['notes'] ?? null);

        return back()->with('success', 'تم إنجاز أمر العمل');
    }

    public function reopen(WorkOrder $order)
    {
        $this->orders->reopen($order);

        return back()->with('success', 'أُعيد فتح الأمر');
    }

    public function cancel(Request $request, WorkOrder $order)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->orders->cancel($order, $data['reason'] ?? null);

        return back()->with('success', 'أُلغي أمر العمل وأُرجعت قطعه إلى المستودع');
    }

    public function sign(Request $request, WorkOrder $order)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'signature' => 'required|string'], [], ['name' => 'اسم الموقّع']);
        $this->orders->sign($order, $data['name'], $data['signature']);

        return back()->with('success', 'سُجّل توقيع العميل');
    }

    public function invoice(WorkOrder $order)
    {
        $order = $this->billing->invoice($order);

        return redirect()->route('work-orders.show', $order->id)->with('success', 'صدرت الفاتورة');
    }

    public function storeTime(Request $request, WorkOrder $order)
    {
        $data = $request->validate(['employee_id' => 'required|tenant_exists:employees,id', 'minutes' => 'required|integer|min:1|max:1440', 'billable' => 'nullable|boolean', 'note' => 'nullable|string|max:255']);
        $this->time->addManual($order, Employee::findOrFail($data['employee_id']), (int) $data['minutes'], null, $request->boolean('billable', true), $data['note'] ?? null);

        return back()->with('success', 'أُضيفت الساعات');
    }

    public function updateTime(Request $request, TimeEntry $entry)
    {
        $data = $request->validate(['minutes' => 'required|integer|min:0|max:1440', 'billable' => 'nullable|boolean']);
        $this->time->edit($entry, (int) $data['minutes'], $request->boolean('billable', true));

        return back()->with('success', 'عُدّل السجل');
    }

    public function stopTime(TimeEntry $entry)
    {
        $this->time->stop($entry);

        return back()->with('success', 'أُوقف المؤقت');
    }

    public function storePart(Request $request, WorkOrder $order)
    {
        $data = $request->validate(['product_id' => 'required|tenant_exists:products,id', 'quantity' => 'required|numeric|gt:0', 'unit_price' => 'nullable|numeric|min:0']);
        $this->parts->consume($order, (int) $data['product_id'], (float) $data['quantity'], isset($data['unit_price']) ? (float) $data['unit_price'] : null);

        return back()->with('success', 'سُجّلت القطعة وخرجت من المخزون');
    }

    public function returnPart(WorkOrderPart $part)
    {
        $this->parts->giveBack($part);

        return back()->with('success', 'أُرجعت القطعة إلى المستودع');
    }

    public function storeAttachment(Request $request, WorkOrder $order)
    {
        $request->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf'], [], ['file' => 'الملف']);
        $stored = FileController::storeForTenant($request->file('file'), 'work-orders');
        $order->attachments()->create(['path' => $stored['path'], 'name' => $request->file('file')->getClientOriginalName(), 'kind' => 'photo', 'uploaded_by' => $request->user()->id]);

        return back()->with('success', 'رُفع الملف');
    }

    protected function fileUrl(string $path): string
    {
        return route('files.show', ['token' => Crypt::encryptString($path)], false);
    }

    protected function lookups(): array
    {
        return [
            'workTypes' => WorkType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'hourly_rate']),
            'technicians' => Employee::orderBy('name')->limit(500)->get(['id', 'name']),
            'warehouses' => Warehouse::orderBy('name_ar')->get(['id', 'name_ar']),
            'priorities' => WorkOrder::PRIORITIES,
        ];
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'ticket_id' => 'nullable|tenant_exists:tickets,id',
            'booking_id' => 'nullable|tenant_exists:bookings,id',
            'work_type_id' => 'nullable|tenant_exists:work_types,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'site_address' => 'nullable|string|max:255',
            'site_phone' => 'nullable|string|max:40',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'scheduled_start' => 'nullable|date_format:Y-m-d H:i',
            'scheduled_end' => 'nullable|date_format:Y-m-d H:i|after_or_equal:scheduled_start',
            'assigned_to' => 'nullable|tenant_exists:employees,id',
            'crew' => 'nullable|array',
            'crew.*' => 'integer|tenant_exists:employees,id',
            'tasks' => 'nullable|array|max:60',
            'tasks.*' => 'nullable|string|max:255',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
        ], [], ['customer_id' => 'العميل', 'title' => 'العنوان']);
    }
}

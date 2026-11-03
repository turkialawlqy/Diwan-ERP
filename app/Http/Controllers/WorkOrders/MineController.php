<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileController;
use App\Models\Employee;
use App\Models\Product;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\WorkOrders\PartsService;
use App\Services\WorkOrders\TimeTracker;
use App\Services\WorkOrders\WorkOrderService;
use App\Services\WorkOrders\WorkOrderSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The technician's phone screen: his own orders only (the lead or in the crew), timer, checklist, parts, photos, signature. */
class MineController extends Controller
{
    public function __construct(protected WorkOrderService $orders, protected TimeTracker $time, protected PartsService $parts, protected WorkOrderSettings $settings) {}

    protected function employee(): ?Employee
    {
        return Employee::where('user_id', auth()->id())->first();
    }

    /** The order, only when this technician belongs to it (404 otherwise, like a record that does not exist). */
    protected function mine(int $id): array
    {
        $employee = $this->employee();
        abort_unless($employee, 404);
        $order = WorkOrder::findOrFail($id);
        abort_unless($order->hasTechnician($employee->id), 404);

        return [$order, $employee];
    }

    public function index(): Response
    {
        $employee = $this->employee();
        $base = fn () => WorkOrder::query()->with(['customer:id,name', 'workType:id,name'])
            ->where(fn ($q) => $q->where('assigned_to', $employee?->id)->orWhereHas('crew', fn ($c) => $c->where('employees.id', $employee?->id)));

        return Inertia::render('WorkOrders/Mine/Index', [
            'hasEmployee' => (bool) $employee,
            'open' => $employee ? $base()->whereIn('status', WorkOrder::OPEN)->orderByRaw('scheduled_start is null')->orderBy('scheduled_start')->get(['id', 'number', 'title', 'customer_id', 'work_type_id', 'status', 'priority', 'scheduled_start', 'site_address']) : [],
            'recent' => $employee ? $base()->where('status', 'done')->where('completed_at', '>=', now()->subDays(3))->latest('completed_at')->get(['id', 'number', 'title', 'customer_id', 'status', 'completed_at']) : [],
            'running' => $employee ? $this->time->running($employee)?->only(['id', 'work_order_id', 'started_at']) : null,
            'statuses' => WorkOrder::STATUSES,
        ]);
    }

    public function show(int $order): Response
    {
        [$order, $employee] = $this->mine($order);
        $order->load(['customer:id,name,phone', 'workType:id,name', 'tasks', 'parts.product:id,name_ar', 'attachments']);
        $running = $this->time->running($employee);

        return Inertia::render('WorkOrders/Mine/Show', [
            'order' => collect($order->only(['id', 'number', 'title', 'description', 'status', 'priority', 'site_address', 'site_phone', 'scheduled_start', 'scheduled_end', 'completion_notes', 'signed_by', 'signed_at']))->all()
                + ['customer' => $order->customer?->only(['name', 'phone']), 'work_type' => $order->workType?->name, 'tasks' => $order->tasks,
                    'parts' => $order->parts->where('returned', false)->map(fn ($p) => ['id' => $p->id, 'name' => $p->product?->name_ar, 'quantity' => (float) $p->quantity])->values(),
                    'photos' => $order->attachments->map(fn ($a) => ['id' => $a->id, 'url' => route('files.show', ['token' => Crypt::encryptString($a->path)], false)])->values(),
                    'signed' => (bool) $order->customer_signature_path],
            'running' => $running ? ['id' => $running->id, 'work_order_id' => $running->work_order_id, 'started_at' => $running->started_at->toIso8601String()] : null,
            'minutes' => $this->time->totals($order)['minutes'],
            'rules' => $this->settings->all(),
            'statuses' => WorkOrder::STATUSES,
            'open' => $order->isOpen(),
        ]);
    }

    public function start(int $order)
    {
        [$order, $employee] = $this->mine($order);
        $this->time->start($order, $employee);

        return back()->with('success', 'بدأ المؤقت');
    }

    public function stop(int $order)
    {
        [$order, $employee] = $this->mine($order);
        $entry = $this->time->running($employee);
        if (! $entry || (int) $entry->work_order_id !== $order->id) {
            throw ValidationException::withMessages(['timer' => 'لا مؤقت يعمل على هذا الأمر.']);
        }
        $this->time->stop($entry);

        return back()->with('success', 'أُوقف المؤقت');
    }

    public function hold(int $order)
    {
        [$order] = $this->mine($order);
        $this->orders->hold($order);

        return back()->with('success', 'عُلّق الأمر');
    }

    public function task(Request $request, int $order, int $task)
    {
        [$order] = $this->mine($order);
        abort_unless($order->isOpen(), 422);
        $item = WorkOrderTask::where('work_order_id', $order->id)->findOrFail($task);
        $done = ! $item->is_done;
        $item->update(['is_done' => $done, 'done_by' => $done ? $request->user()->id : null, 'done_at' => $done ? now() : null]);

        return back();
    }

    public function part(Request $request, int $order)
    {
        [$order] = $this->mine($order);
        $data = $request->validate(['product_id' => 'required|tenant_exists:products,id', 'quantity' => 'required|numeric|gt:0']);
        $this->parts->consume($order, (int) $data['product_id'], (float) $data['quantity']);

        return back()->with('success', 'سُجّلت القطعة');
    }

    public function products(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) < 1, 422);

        return response()->json(['data' => Product::where('is_active', true)
            ->where(fn ($w) => $w->where('name_ar', 'like', "%{$q}%")->orWhere('sku', 'like', "{$q}%")->orWhere('barcode', $q))
            ->orderBy('name_ar')->limit(15)->get(['id', 'name_ar', 'sku', 'selling_price'])]);
    }

    public function photo(Request $request, int $order)
    {
        [$order] = $this->mine($order);
        $request->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp'], [], ['file' => 'الصورة']);
        $stored = FileController::storeForTenant($request->file('file'), 'work-orders');
        $order->attachments()->create(['path' => $stored['path'], 'name' => $request->file('file')->getClientOriginalName(), 'kind' => 'photo', 'uploaded_by' => $request->user()->id]);

        return back()->with('success', 'رُفعت الصورة');
    }

    public function sign(Request $request, int $order)
    {
        [$order] = $this->mine($order);
        $data = $request->validate(['name' => 'required|string|max:120', 'signature' => 'required|string'], [], ['name' => 'اسم الموقّع']);
        $this->orders->sign($order, $data['name'], $data['signature']);

        return back()->with('success', 'سُجّل توقيع العميل');
    }

    public function complete(Request $request, int $order)
    {
        [$order] = $this->mine($order);
        $data = $request->validate(['notes' => 'nullable|string|max:5000']);
        $this->orders->complete($order, $data['notes'] ?? null);

        return redirect()->route('work-orders.mine.index')->with('success', 'تم إنجاز الأمر '.$order->number);
    }
}

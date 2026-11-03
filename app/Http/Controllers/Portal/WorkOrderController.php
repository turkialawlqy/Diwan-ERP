<?php

namespace App\Http\Controllers\Portal;

use App\Models\WorkOrder;
use Illuminate\Http\Request;

/** The customer follows the service visits made for him. Costs, margin and internal notes never leave the server. */
class WorkOrderController extends PortalController
{
    public function index(Request $request)
    {
        $this->needs($request, 'work_orders');

        return $this->page($request, 'Portal/WorkOrders', [
            'orders' => $this->scope()->workOrders()->with('workType:id,name')->latest('id')->paginate(20, ['id', 'number', 'title', 'work_type_id', 'status', 'scheduled_start', 'completed_at'])->withQueryString(),
            'statuses' => WorkOrder::STATUSES,
        ]);
    }

    public function show(Request $request, int $company, int $order)
    {
        $this->needs($request, 'work_orders');
        $order = $this->scope()->workOrders()->with(['workType:id,name', 'lead:id,name', 'tasks:id,work_order_id,title,is_done', 'invoice:id,invoice_number'])->findOrFail($order);

        return $this->page($request, 'Portal/WorkOrderShow', [
            'order' => $order->only(['id', 'number', 'title', 'description', 'status', 'site_address', 'scheduled_start', 'scheduled_end', 'started_at', 'completed_at', 'completion_notes', 'signed_by', 'signed_at'])
                + ['work_type' => $order->workType?->name, 'technician' => $order->lead?->name, 'tasks' => $order->tasks->map(fn ($t) => ['title' => $t->title, 'done' => (bool) $t->is_done])->values(),
                    'invoice' => $order->invoice ? ['id' => $order->invoice->id, 'number' => $order->invoice->invoice_number] : null],
            'statuses' => WorkOrder::STATUSES,
        ]);
    }
}

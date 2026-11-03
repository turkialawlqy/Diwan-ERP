<?php

namespace App\Services\WorkOrders;

use App\Models\WorkOrder;
use App\Services\Sales\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Billing a finished work order: labour hours at the work type's rate plus the parts at their prices. */
class WorkOrderBilling
{
    public function __construct(protected InvoiceService $invoices, protected TimeTracker $time, protected PartsService $parts, protected WorkOrderService $orders) {}

    /** What an invoice would contain now (also the preview on the order page). */
    public function preview(WorkOrder $order): array
    {
        $totals = $this->time->totals($order);
        $type = $order->workType;
        $lines = [];

        if ($totals['billable_minutes'] > 0 && $type) {
            $minutes = max($totals['billable_minutes'], (int) $type->min_billable_minutes);
            $lines[] = [
                'product_id' => $type->product_id, 'item_name' => $type->name, 'description' => 'ساعات العمل: '.round($minutes / 60, 2).' ساعة — أمر العمل '.$order->number,
                'quantity' => round($minutes / 60, 2), 'unit_price' => (float) $type->hourly_rate, 'tax_rate' => (float) $type->tax_rate,
            ];
        }
        foreach ($order->parts()->where('returned', false)->with('product:id,name_ar,tax_rate')->get() as $part) {
            $lines[] = [
                'product_id' => $part->product_id, 'item_name' => $part->product?->name_ar ?? 'قطعة', 'description' => 'قطعة مستهلكة — أمر العمل '.$order->number,
                'quantity' => (float) $part->quantity, 'unit_price' => (float) $part->unit_price, 'tax_rate' => (float) ($part->product?->tax_rate ?? 0),
            ];
        }
        $revenue = round(collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price']), 2);
        $cost = round($totals['cost'] + $this->parts->cost($order), 2);

        return ['lines' => $lines, 'revenue' => $revenue, 'cost' => $cost, 'margin' => round($revenue - $cost, 2), 'parts_cost' => $this->parts->cost($order), 'labour_cost' => $totals['cost']];
    }

    public function invoice(WorkOrder $order): WorkOrder
    {
        if ($order->status !== 'done' || $order->invoice_id) {
            throw ValidationException::withMessages(['status' => 'تُفوتَر الأوامر المنجزة غير المفوتَرة فقط.']);
        }

        return DB::transaction(function () use ($order) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($order);
            if (! $preview['lines']) {
                throw ValidationException::withMessages(['status' => 'لا شيء للفوترة: لا ساعات قابلة للفوترة (تحقق من نوع العمل) ولا قطع.']);
            }

            // Stock already left when the parts were consumed; the invoice books the cost of goods once, from this cost.
            $invoice = $this->invoices->create([
                'customer_id' => $order->customer_id,
                'invoice_date' => now()->toDateString(),
                'supply_date' => ($order->completed_at ?? now())->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'reference_number' => $order->number,
                'notes' => "أمر عمل {$order->number} — {$order->title}",
            ], $preview['lines'], ['issue_stock' => false, 'check_credit' => false, 'cost' => $preview['parts_cost']]);

            $order->update([
                'invoice_id' => $invoice->id, 'status' => 'invoiced',
                'labour_amount' => round(collect($preview['lines'])->filter(fn ($l) => ! str_starts_with($l['description'], 'قطعة'))->sum(fn ($l) => $l['quantity'] * $l['unit_price']), 2),
                'parts_amount' => $this->parts->amount($order),
                'cost_amount' => $preview['cost'],
            ]);

            return $order;
        });
    }
}

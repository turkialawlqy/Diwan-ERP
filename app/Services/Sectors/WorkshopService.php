<?php

namespace App\Services\Sectors;

use App\Models\CustomerVehicle;
use App\Models\WorkOrder;
use App\Notifications\BookingMessage;
use Illuminate\Support\Facades\Notification;

/** The workshop side: a finished order updates the vehicle card, and customers are reminded of the next service. */
class WorkshopService
{
    /** Called when a work order is completed: odometer, then the next service date / km from the work type's interval. */
    public function onCompleted(WorkOrder $order): void
    {
        if (! $order->vehicle_id || ! ($vehicle = CustomerVehicle::find($order->vehicle_id))) {
            return;
        }
        $km = max((int) $vehicle->odometer, (int) ($order->odometer_in ?? 0));
        $update = ['odometer' => $km];
        $type = $order->workType;
        if ($type?->service_interval_days) {
            $update['next_service_date'] = ($order->completed_at ?? now())->copy()->addDays($type->service_interval_days)->toDateString();
            $update['reminder_state'] = null;
        }
        if ($type?->service_interval_km) {
            $update['next_service_km'] = $km + $type->service_interval_km;
        }
        $vehicle->update($update);
    }

    /** Mail customers whose vehicle is due within a week, once per due date. */
    public function remind(int $days = 7): int
    {
        $n = 0;
        CustomerVehicle::with('customer:id,name,email')->whereNotNull('next_service_date')->whereDate('next_service_date', '>=', today())->whereDate('next_service_date', '<=', today()->addDays($days))->get()
            ->each(function (CustomerVehicle $v) use (&$n) {
                $due = $v->next_service_date->toDateString();
                if (($v->reminder_state['date'] ?? null) === $due) {
                    return;
                }
                $v->update(['reminder_state' => ['date' => $due]]);
                if ($v->customer?->email) {
                    try {
                        Notification::route('mail', $v->customer->email)->notify(new BookingMessage('موعد الصيانة الدورية لمركبتك يقترب', [
                            "المركبة: {$v->label()}", "موعد الصيانة المقترح: {$due}", $v->next_service_km ? "أو عند بلوغ العداد {$v->next_service_km} كم" : '', 'نسعد بخدمتك، احجز موعدك معنا.',
                        ]));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                $n++;
            });

        return $n;
    }
}

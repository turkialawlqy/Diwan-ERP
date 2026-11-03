<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\ErpAlert;

/** The 15-minute tick: reminders to customers (once per step), unconfirmed-booking alerts, package expiry. */
class BookingReminders
{
    public function __construct(protected BookingSettings $settings, protected BookingService $bookings, protected PackageService $packages) {}

    /** @return array{reminders:int,unconfirmed:int,expired:int} */
    public function tick(): array
    {
        $done = ['reminders' => 0, 'unconfirmed' => 0, 'expired' => $this->packages->expire()];
        $steps = collect($this->settings->reminderHours())->sort()->values(); // smallest first
        $horizon = $steps->max();

        if ($horizon) {
            Booking::with(['customer:id,name,email', 'service:id,name'])->where('status', 'confirmed')
                ->where('starts_at', '>', now())->where('starts_at', '<=', now()->addHours($horizon))->get()
                ->each(function (Booking $b) use ($steps, &$done) {
                    $state = $b->reminder_state ?? [];
                    $step = $steps->first(fn ($h) => $b->starts_at->lte(now()->addHours($h)));
                    if (! $step || ! empty($state["h{$step}"])) {
                        return;
                    }
                    foreach ($steps->filter(fn ($h) => $h >= $step) as $h) {
                        $state["h{$h}"] = true; // the larger steps are covered by this one
                    }
                    $b->update(['reminder_state' => $state]);
                    $this->bookings->tellCustomer($b, 'تذكير بموعدك '.$b->number);
                    $done['reminders']++;
                });
        }

        Booking::with(['customer:id,name', 'service:id,name'])->where('status', 'pending')->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDay())->get()
            ->each(function (Booking $b) use (&$done) {
                $state = $b->reminder_state ?? [];
                if (! empty($state['unconfirmed'])) {
                    return;
                }
                $b->update(['reminder_state' => $state + ['unconfirmed' => true]]);
                $url = route('bookings.show', $b->id, false);
                User::where('company_id', $b->company_id)->where('status', 'active')->get()->filter(fn (User $u) => $u->can('bookings.bookings.edit'))
                    ->each(fn (User $u) => $u->notify(new ErpAlert('booking_unconfirmed', 'حجز لم يُؤكَّد يقترب موعده', "{$b->number} — {$b->customer->name} — ".$b->starts_at->format('Y-m-d H:i'), $url, 'warning')));
                $done['unconfirmed']++;
            });

        return $done;
    }
}

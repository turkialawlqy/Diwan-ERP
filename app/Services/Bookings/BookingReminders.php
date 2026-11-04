<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\ErpAlert;

/** The 15-minute tick: reminders to customers (once per step), unconfirmed-booking alerts, package expiry. */
class BookingReminders
{
    /** Hours of the largest difference between two branch zones. */
    protected const ZONE_SLACK = 14;

    public function __construct(protected BookingSettings $settings, protected BookingService $bookings, protected PackageService $packages) {}

    /** @return array{reminders:int,unconfirmed:int,expired:int} */
    public function tick(): array
    {
        $done = ['reminders' => 0, 'unconfirmed' => 0, 'expired' => $this->packages->expire()];
        $steps = collect($this->settings->reminderHours())->sort()->values(); // smallest first
        $horizon = $steps->max();

        if ($horizon) {
            // The query is widened by the largest zone difference; each booking is then judged on its own branch's clock.
            Booking::with(['customer:id,name,email', 'service:id,name'])->where('status', 'confirmed')
                ->where('starts_at', '>', now()->subHours(self::ZONE_SLACK))->where('starts_at', '<=', now()->addHours($horizon + self::ZONE_SLACK))->get()
                ->filter(fn (Booking $b) => $b->starts_at->gt(BookingClock::now($b->branch_id)))
                ->each(function (Booking $b) use ($steps, &$done) {
                    $state = $b->reminder_state ?? [];
                    $step = $steps->first(fn ($h) => $b->starts_at->lte(BookingClock::now($b->branch_id)->addHours($h)));
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

        Booking::with(['customer:id,name', 'service:id,name'])->where('status', 'pending')->where('starts_at', '>', now()->subHours(self::ZONE_SLACK))->where('starts_at', '<=', now()->addDay()->addHours(self::ZONE_SLACK))->get()
            ->filter(fn (Booking $b) => $b->starts_at->gt($now = BookingClock::now($b->branch_id)) && $b->starts_at->lte($now->copy()->addDay()))
            ->each(function (Booking $b) use (&$done) {
                $state = $b->reminder_state ?? [];
                if (! empty($state['unconfirmed'])) {
                    return;
                }
                $b->update(['reminder_state' => $state + ['unconfirmed' => true]]);
                $url = route('bookings.show', $b->id, false);
                User::where('company_id', $b->company_id)->where('status', 'active')->get()->filter(fn (User $u) => $u->can('bookings.bookings.edit'))
                    ->each(fn (User $u) => $u->notify(new ErpAlert('booking_unconfirmed', 'حجز لم يُؤكَّد يقترب موعده', "{$b->number} — {$b->customer?->name} — ".$b->starts_at->format('Y-m-d H:i'), $url, 'warning')));
                $done['unconfirmed']++;
            });

        return $done;
    }
}

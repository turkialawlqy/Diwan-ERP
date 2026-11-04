<?php

namespace App\Services\Bookings;

use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\PublicHoliday;
use App\Models\ResourceTimeOff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which start times can still be booked: a resource's weekly hours (breaks are gaps between rows),
 * minus holidays, time off and the time other bookings occupy (their length plus their service's buffer).
 */
class AvailabilityService
{
    public function __construct(protected BookingSettings $settings) {}

    /**
     * Free start times of one resource on a day, for a service.
     *
     * @return Collection<int, array{time:string,starts_at:string,resource_id:int}>
     */
    public function slotsFor(BookingResource $resource, BookableService $service, Carbon $day, bool $enforceWindow = true, ?int $ignoreBookingId = null): Collection
    {
        $day = $day->copy()->startOfDay();
        $clock = BookingClock::now($resource->branch_id); // the resource's branch has its own wall clock
        if (! $resource->is_active || ($enforceWindow && ! $this->withinWindow($day, $clock)) || PublicHoliday::covers($day)) {
            return collect();
        }

        // Hours that end after midnight (22:00–02:00) belong to the day they start on and run into the next:
        // such a row also opens the early hours of the day after it.
        $own = $resource->hours()->where('weekday', $day->isoWeekday())->get();
        $before = $resource->hours()->where('weekday', $day->copy()->subDay()->isoWeekday())->get()->filter(fn ($h) => $h->end_time <= $h->start_time);
        if ($own->isEmpty() && $before->isEmpty()) {
            return collect();
        }
        $windows = $own->map(fn ($h) => [$day->copy()->setTimeFromTimeString($h->start_time), $this->endOf($day, $h)])
            ->concat($before->map(fn ($h) => [$day->copy()->subDay()->setTimeFromTimeString($h->start_time), $day->copy()->setTimeFromTimeString($h->end_time)]));

        $step = $this->settings->all()['slot_step'];
        $duration = (int) $service->duration_minutes;
        $earliest = $enforceWindow ? $clock->copy()->addHours($this->settings->all()['min_notice_hours']) : null;
        $busy = $this->busy($resource, $day, $ignoreBookingId);
        $offs = ResourceTimeOff::where('booking_resource_id', $resource->id)->where('starts_at', '<', $day->copy()->endOfDay())->where('ends_at', '>', $day)->get();
        $slots = collect();

        foreach ($windows as [$from, $to]) {
            for ($start = $from->copy(); $start->copy()->addMinutes($duration)->lte($to); $start->addMinutes($step)) {
                if ($start->lt($day) || $start->gte($day->copy()->addDay())) {
                    continue; // a start on the other day is that day's slot
                }
                $end = $start->copy()->addMinutes($duration);
                $occupiedEnd = $end->copy()->addMinutes((int) $service->buffer_minutes);
                if ($earliest && $start->lt($earliest)) {
                    continue;
                }
                if ($offs->contains(fn ($o) => $start->lt($o->ends_at) && $end->gt($o->starts_at))) {
                    continue;
                }
                $overlapping = $busy->filter(fn ($b) => $start->lt($b['end']) && $occupiedEnd->gt($b['start']))->count();
                if ($overlapping >= max(1, (int) $resource->capacity)) {
                    continue;
                }
                $slots->push(['time' => $start->format('H:i'), 'starts_at' => $start->format('Y-m-d H:i'), 'resource_id' => $resource->id]);
            }
        }

        return $slots->unique('time')->values();
    }

    /**
     * Free start times across the resources that can serve the service (or one chosen); each time
     * carries the least-loaded resource that is free then.
     */
    public function slots(BookableService $service, ?BookingResource $resource, Carbon $day, bool $enforceWindow = true): Collection
    {
        $resources = $resource ? collect([$resource]) : $service->resources()->where('is_active', true)->get();
        // Every branch's bookings count: a shared resource (no branch) is one calendar, whoever booked it.
        $load = Booking::withoutGlobalScope('branch')->whereIn('booking_resource_id', $resources->pluck('id'))->whereDate('starts_at', $day)->whereIn('status', Booking::BLOCKING)
            ->selectRaw('booking_resource_id, count(*) as n')->groupBy('booking_resource_id')->pluck('n', 'booking_resource_id');

        $byTime = [];
        foreach ($resources as $r) {
            foreach ($this->slotsFor($r, $service, $day, $enforceWindow) as $slot) {
                $current = $byTime[$slot['time']] ?? null;
                if (! $current || ($load[$r->id] ?? 0) < ($load[$current['resource_id']] ?? 0)) {
                    $byTime[$slot['time']] = $slot;
                }
            }
        }
        ksort($byTime);

        return collect(array_values($byTime));
    }

    public function isFree(BookingResource $resource, BookableService $service, Carbon $start, bool $enforceWindow = true, ?int $ignoreBookingId = null): bool
    {
        return $this->slotsFor($resource, $service, $start, $enforceWindow, $ignoreBookingId)->contains('time', $start->format('H:i'));
    }

    protected function withinWindow(Carbon $day, ?Carbon $clock = null): bool
    {
        $today = ($clock ?? now())->copy()->startOfDay();

        return $day->gte($today) && $day->lte($today->copy()->addDays($this->settings->all()['max_advance_days']));
    }

    /** End of a weekly row on the day it starts: after midnight when it ends at or before its start. */
    protected function endOf(Carbon $day, $hours): Carbon
    {
        $end = $day->copy()->setTimeFromTimeString($hours->end_time);

        return $hours->end_time <= $hours->start_time ? $end->addDay() : $end;
    }

    /** @return Collection<int, array{start:Carbon,end:Carbon}> occupied intervals (booking length + its service's buffer) */
    protected function busy(BookingResource $resource, Carbon $day, ?int $ignoreBookingId): Collection
    {
        return Booking::withoutGlobalScope('branch')->with(['service' => fn ($q) => $q->withoutGlobalScope('branch')->select('id', 'buffer_minutes')])->where('booking_resource_id', $resource->id)->whereIn('status', Booking::BLOCKING)
            ->where('starts_at', '<', $day->copy()->addDay()->addHours(6))->where('ends_at', '>', $day->copy()->subHours(6))
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))->get()
            ->map(fn (Booking $b) => ['start' => $b->starts_at, 'end' => $b->ends_at->copy()->addMinutes((int) $b->service?->buffer_minutes)]);
    }
}

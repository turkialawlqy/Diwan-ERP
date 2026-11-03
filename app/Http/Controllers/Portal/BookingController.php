<?php

namespace App\Http\Controllers\Portal;

use App\Models\BookableService;
use App\Models\Booking;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The customer books, follows and cancels his own appointments (online services only). */
class BookingController extends PortalController
{
    public function __construct(protected BookingService $bookings, protected AvailabilityService $availability) {}

    public function index(Request $request)
    {
        $this->needs($request, 'bookings');
        $scope = $this->scope();
        $columns = ['id', 'number', 'bookable_service_id', 'booking_resource_id', 'starts_at', 'status'];

        return $this->page($request, 'Portal/Bookings', [
            'upcoming' => $scope->bookings()->with(['service:id,name', 'resource:id,name'])->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', now())->orderBy('starts_at')->get($columns),
            'past' => $scope->bookings()->with(['service:id,name', 'resource:id,name'])->where(fn ($q) => $q->whereNotIn('status', ['pending', 'confirmed'])->orWhere('starts_at', '<', now()))->orderByDesc('starts_at')->limit(30)->get($columns),
            'statuses' => Booking::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $this->needs($request, 'bookings');

        return $this->page($request, 'Portal/BookingForm', [
            'services' => BookableService::where('is_active', true)->where('online_bookable', true)->with('resources:id,name,is_active')->orderBy('name')->get()
                ->map(fn ($s) => $s->only(['id', 'name', 'description', 'duration_minutes', 'price']) + ['resources' => $s->resources->where('is_active', true)->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()])
                ->filter(fn ($s) => $s['resources']->isNotEmpty())->values(),
        ]);
    }

    public function slots(Request $request)
    {
        $this->needs($request, 'bookings');
        $d = $request->validate(['service' => 'required|integer', 'resource' => 'nullable|integer', 'date' => 'required|date']);
        $service = BookableService::where('is_active', true)->where('online_bookable', true)->findOrFail($d['service']);
        $resource = ! empty($d['resource']) ? $service->resources()->where('booking_resources.is_active', true)->findOrFail($d['resource']) : null;
        $day = Carbon::parse($d['date']);

        $slots = $resource ? $this->availability->slotsFor($resource, $service, $day) : $this->availability->slots($service, null, $day);

        // The customer only needs the times; the resource stays server-side for "any".
        return response()->json(['data' => $slots->map(fn ($s) => ['time' => $s['time'], 'starts_at' => $s['starts_at']])->values()]);
    }

    public function store(Request $request, int $company)
    {
        $this->needs($request, 'bookings');
        $data = $request->validate([
            'bookable_service_id' => 'required|integer',
            'booking_resource_id' => 'nullable|integer',
            'starts_at' => 'required|date_format:Y-m-d H:i',
            'notes' => 'nullable|string|max:1000',
        ], [], ['bookable_service_id' => 'الخدمة', 'starts_at' => 'الموعد']);

        $user = auth('portal')->user();
        $booking = $this->bookings->create(['customer_id' => $user->customer_id, 'deposit_amount' => 0] + $data, 'portal', null, $user);

        return redirect()->route('customer-portal.bookings.show', ['company' => $company, 'booking' => $booking->id])
            ->with('success', $booking->status === 'confirmed' ? 'تم حجز موعدك '.$booking->number : 'استلمنا طلب حجزك '.$booking->number.' وسنؤكده قريبًا');
    }

    public function show(Request $request, int $company, int $booking)
    {
        $this->needs($request, 'bookings');
        $booking = $this->scope()->bookings()->with(['service:id,name,free_cancel_hours', 'resource:id,name'])->findOrFail($booking);
        $limit = $booking->starts_at->copy()->subHours((int) $booking->service->free_cancel_hours);

        return $this->page($request, 'Portal/BookingShow', [
            'booking' => $booking->only(['id', 'number', 'starts_at', 'ends_at', 'status', 'price', 'member_discount', 'cancel_reason']) + ['service' => $booking->service?->name, 'resource' => $booking->resource?->name],
            'canCancel' => $booking->isOpen() && now()->lte($limit),
            'cancelHours' => (int) $booking->service->free_cancel_hours,
            'statuses' => Booking::STATUSES,
        ]);
    }

    public function cancel(Request $request, int $company, int $booking)
    {
        $this->needs($request, 'bookings');
        $booking = $this->scope()->bookings()->findOrFail($booking);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->bookings->cancel($booking, $data['reason'] ?? null, true);

        return back()->with('success', 'تم إلغاء الحجز');
    }
}

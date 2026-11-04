<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\CustomerPackage;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Staff side of bookings: calendar, list, create / move, and the status actions. */
class BookingController extends Controller
{
    public function __construct(protected BookingService $bookings, protected AvailabilityService $availability) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => 'nullable|string', 'resource' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date', 'search' => 'nullable|string|max:100']);

        $bookings = Booking::with(['customer:id,name,phone', 'service:id,name', 'resource:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['resource'] ?? null, fn ($q, $r) => $q->where('booking_resource_id', $r))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('starts_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('starts_at', '<=', $d))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('starts_at')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Bookings/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $f['status'] ?? '', 'resource' => $f['resource'] ?? '', 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? '', 'search' => $f['search'] ?? ''],
            'statuses' => Booking::STATUSES,
            'resources' => BookingResource::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'today' => Booking::whereDate('starts_at', today())->whereIn('status', ['pending', 'confirmed'])->count(),
                'pending' => Booking::where('status', 'pending')->where('starts_at', '>=', now())->count(),
            ],
        ]);
    }

    /** One day, a column per resource. */
    public function calendar(Request $request): Response
    {
        $day = Carbon::parse($request->validate(['date' => 'nullable|date'])['date'] ?? today());
        $resources = BookingResource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'color', 'capacity'])
            ->each(fn ($r) => $r->setRelation('hours', $r->hours()->where('weekday', $day->isoWeekday())->get(['start_time', 'end_time'])));

        return Inertia::render('Bookings/Calendar', [
            'date' => $day->toDateString(),
            'resources' => $resources,
            'bookings' => Booking::with(['customer:id,name', 'service:id,name'])->whereDate('starts_at', $day)->whereIn('status', ['pending', 'confirmed', 'completed', 'no_show'])
                ->get(['id', 'number', 'customer_id', 'bookable_service_id', 'booking_resource_id', 'starts_at', 'ends_at', 'status']),
            'statuses' => Booking::STATUSES,
        ]);
    }

    /** Free start times (JSON) for the booking form. */
    public function slots(Request $request)
    {
        $d = $request->validate(['service' => 'required|integer', 'resource' => 'nullable|integer', 'date' => 'required|date', 'ignore' => 'nullable|integer']);
        $service = BookableService::findOrFail($d['service']);
        $resource = ! empty($d['resource']) ? BookingResource::findOrFail($d['resource']) : null;
        $day = Carbon::parse($d['date']);

        $slots = $resource
            ? $this->availability->slotsFor($resource, $service, $day, false, $d['ignore'] ?? null)
            : $this->availability->slots($service, null, $day, false);

        return response()->json(['data' => $slots->values()]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Bookings/Form', ['booking' => null, 'prefill' => $request->only(['customer', 'service', 'resource', 'starts_at'])] + $this->lookups());
    }

    public function edit(Booking $booking): Response
    {
        abort_unless($booking->isOpen(), 404);

        return Inertia::render('Bookings/Form', ['booking' => $booking->load('customer:id,name'), 'prefill' => []] + $this->lookups());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'bookable_service_id' => 'required|tenant_exists:bookable_services,id',
            'booking_resource_id' => 'nullable|tenant_exists:booking_resources,id',
            'starts_at' => 'required|date_format:Y-m-d H:i',
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_method' => 'nullable|in:cash,card,bank_transfer',
            'deposit_bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'notes' => 'nullable|string|max:2000',
        ], [], ['customer_id' => 'العميل', 'bookable_service_id' => 'الخدمة', 'starts_at' => 'الموعد']);

        $booking = $this->bookings->create($data, 'staff', $request->user());

        return redirect()->route('bookings.show', $booking->id)->with('success', 'تم إنشاء الحجز '.$booking->number);
    }

    public function update(Request $request, Booking $booking)
    {
        $data = $request->validate(['booking_resource_id' => 'nullable|tenant_exists:booking_resources,id', 'starts_at' => 'required|date_format:Y-m-d H:i', 'notes' => 'nullable|string|max:2000']);
        $this->bookings->reschedule($booking, $data['starts_at'], $data['booking_resource_id'] ?? null);
        $booking->update(['notes' => $data['notes'] ?? $booking->notes]);

        return redirect()->route('bookings.show', $booking->id)->with('success', 'تم تعديل الحجز');
    }

    public function show(Booking $booking): Response
    {
        $booking->load(['customer:id,name,phone,email', 'service', 'resource:id,name', 'invoice:id,invoice_number,total,remaining_amount', 'package:id,number,name']);
        $covering = $booking->isOpen()
            ? CustomerPackage::where('customer_id', $booking->customer_id)->where('status', 'active')->get()->filter(fn ($p) => $p->covers($booking->bookable_service_id, $booking->starts_at))
                ->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'name' => $p->name, 'remaining' => $p->remaining()])->values()
            : collect();

        return Inertia::render('Bookings/Show', [
            'booking' => $booking,
            'coveringPackages' => $covering,
            'net' => round((float) $booking->price - (float) $booking->member_discount, 2),
            'statuses' => Booking::STATUSES,
        ]);
    }

    public function confirm(Booking $booking)
    {
        $this->bookings->confirm($booking);

        return back()->with('success', 'تم تأكيد الحجز');
    }

    public function complete(Request $request, Booking $booking)
    {
        $data = $request->validate(['customer_package_id' => 'nullable|integer', 'use_package' => 'nullable|boolean']);
        $this->bookings->complete($booking, $data['customer_package_id'] ?? null, $request->boolean('use_package', true));

        return back()->with('success', 'تم إنجاز الحجز');
    }

    public function cancel(Request $request, Booking $booking)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->bookings->cancel($booking, $data['reason'] ?? null);

        return back()->with('success', 'تم إلغاء الحجز');
    }

    public function noShow(Booking $booking)
    {
        $this->bookings->noShow($booking);

        return back()->with('success', 'سُجّل أن العميل لم يحضر');
    }

    protected function lookups(): array
    {
        return [
            'services' => BookableService::where('is_active', true)->with('resources:id')->orderBy('name')->get()
                ->map(fn ($s) => $s->only(['id', 'name', 'duration_minutes', 'price', 'deposit_percent']) + ['resource_ids' => $s->resources->pluck('id')->all()]),
            'resources' => BookingResource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }
}

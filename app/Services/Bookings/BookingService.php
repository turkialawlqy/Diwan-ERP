<?php

namespace App\Services\Bookings;

use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\PortalUser;
use App\Models\User;
use App\Notifications\BookingMessage;
use App\Notifications\ErpAlert;
use App\Services\Documents\DocumentNumberService;
use App\Services\Sales\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** Creating, moving, cancelling and completing bookings (completing bills the customer or uses a prepaid visit). */
class BookingService
{
    public function __construct(
        protected AvailabilityService $availability,
        protected BookingSettings $settings,
        protected DocumentNumberService $numbers,
        protected InvoiceService $invoices,
        protected PackageService $packages,
    ) {}

    /** The best discount percent the customer's live subscriptions give on bookings. */
    public function memberPercent(int $customerId): int
    {
        return (int) CustomerSubscription::with('plan:id,booking_discount_percent')->where('customer_id', $customerId)->whereIn('status', ['trial', 'active'])->get()
            ->max(fn ($s) => (int) $s->plan?->booking_discount_percent);
    }

    /**
     * @param  array  $data  customer_id, bookable_service_id, starts_at (Y-m-d H:i), booking_resource_id? (null = any free one), deposit_amount?, notes?
     * @param  string  $source  staff | portal (portal bookings keep the notice / advance window and only online services)
     */
    public function create(array $data, string $source = 'staff', ?User $user = null, ?PortalUser $portalUser = null): Booking
    {
        $service = BookableService::where('is_active', true)->find($data['bookable_service_id']);
        if (! $service || ($source === 'portal' && ! $service->online_bookable)) {
            throw ValidationException::withMessages(['bookable_service_id' => 'الخدمة غير متاحة للحجز.']);
        }
        $customer = Customer::findOrFail($data['customer_id']);
        $start = Carbon::parse($data['starts_at'])->seconds(0);
        $enforce = $source === 'portal';

        $resource = ! empty($data['booking_resource_id'])
            ? BookingResource::where('is_active', true)->findOrFail($data['booking_resource_id'])
            : $this->pickResource($service, $start, $enforce);
        if (! $service->resources()->whereKey($resource->id)->exists()) {
            throw ValidationException::withMessages(['booking_resource_id' => 'هذا المورد لا يقدّم الخدمة المختارة.']);
        }

        $any = empty($data['booking_resource_id']);

        return DB::transaction(function () use ($data, $source, $user, $portalUser, $service, $customer, $start, $resource, $enforce, $any) {
            BookingResource::whereKey($resource->id)->lockForUpdate()->first(); // two people cannot take the same slot
            if ($any && ! $this->availability->isFree($resource, $service, $start, $enforce)) {
                // «Any resource» and this one was just taken: another free one that serves the service.
                foreach ($service->resources()->where('is_active', true)->whereKeyNot($resource->id)->get() as $other) {
                    BookingResource::whereKey($other->id)->lockForUpdate()->first();
                    if ($this->availability->isFree($other, $service, $start, $enforce)) {
                        $resource = $other;
                        break;
                    }
                }
            }
            if (! $this->availability->isFree($resource, $service, $start, $enforce)) {
                throw ValidationException::withMessages(['starts_at' => 'هذا الموعد غير متاح، اختر وقتًا آخر.']);
            }

            $price = (float) $service->price;
            $discount = round($price * $this->memberPercent($customer->id) / 100, 2);
            $deposit = round(max(0, (float) ($data['deposit_amount'] ?? 0)), 2);
            if ($deposit > $price - $discount + 0.005) {
                throw ValidationException::withMessages(['deposit_amount' => 'العربون أكبر من سعر الحجز.']);
            }

            $confirmed = $source === 'staff' || $this->settings->all()['auto_confirm'];
            $booking = Booking::create([
                'number' => $this->numbers->next('booking', $start),
                'customer_id' => $customer->id,
                'bookable_service_id' => $service->id,
                'booking_resource_id' => $resource->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($service->duration_minutes),
                'status' => $confirmed ? 'confirmed' : 'pending',
                'source' => $source,
                'price' => $price,
                'member_discount' => $discount,
                'deposit_amount' => 0, // set by the receipt below
                'customer_package_id' => $data['customer_package_id'] ?? null,
                'portal_user_id' => $portalUser?->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            if ($deposit > 0) {
                app(BookingDeposits::class)->receive($booking, $deposit, $data['deposit_method'] ?? 'cash', isset($data['deposit_bank_account_id']) ? (int) $data['deposit_bank_account_id'] : null);
            }

            if ($source === 'portal') {
                $this->alertStaff($booking, 'booking_new', 'حجز جديد من البوابة', "{$booking->number} — {$customer->name} — {$service->name} ".$start->format('Y-m-d H:i'), 'info');
            }
            if ($confirmed) {
                $this->tellCustomer($booking, 'تأكيد حجزك '.$booking->number);
            }

            return $booking;
        });
    }

    /** Move an open booking to another time / resource. */
    public function reschedule(Booking $booking, string $startsAt, ?int $resourceId = null): Booking
    {
        $this->assertOpen($booking);

        return DB::transaction(function () use ($booking, $startsAt, $resourceId) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $service = $booking->service;
            $resource = BookingResource::where('is_active', true)->findOrFail($resourceId ?: $booking->booking_resource_id);
            $start = Carbon::parse($startsAt)->seconds(0);
            if (! $service->resources()->whereKey($resource->id)->exists()) {
                throw ValidationException::withMessages(['booking_resource_id' => 'هذا المورد لا يقدّم الخدمة المختارة.']);
            }
            BookingResource::whereKey($resource->id)->lockForUpdate()->first();
            if (! $this->availability->isFree($resource, $service, $start, false, $booking->id)) {
                throw ValidationException::withMessages(['starts_at' => 'هذا الموعد غير متاح، اختر وقتًا آخر.']);
            }

            $booking->update(['booking_resource_id' => $resource->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($service->duration_minutes), 'reminder_state' => null]);
            $this->tellCustomer($booking, 'تم تعديل موعد حجزك '.$booking->number);

            return $booking;
        });
    }

    public function confirm(Booking $booking): Booking
    {
        if ($booking->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'الحجز مؤكد أو مغلق.']);
        }
        $booking->update(['status' => 'confirmed']);
        $this->tellCustomer($booking, 'تأكيد حجزك '.$booking->number);

        return $booking;
    }

    /** Staff cancel any open booking; a portal customer only before the service's free-cancellation window closes. */
    public function cancel(Booking $booking, ?string $reason = null, bool $byCustomer = false): Booking
    {
        $this->assertOpen($booking);
        if ($byCustomer) {
            $limit = $booking->starts_at->copy()->subHours((int) $booking->service->free_cancel_hours);
            if (now()->gt($limit)) {
                throw ValidationException::withMessages(['status' => "لا يمكن إلغاء الحجز قبل أقل من {$booking->service->free_cancel_hours} ساعة من موعده، تواصل معنا."]);
            }
        }
        DB::transaction(function () use (&$booking, $reason) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($booking); // not completed meanwhile
            $booking->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()]);
            app(BookingDeposits::class)->release($booking);
        });
        $this->tellCustomer($booking, 'تم إلغاء حجزك '.$booking->number, $reason ? ["السبب: {$reason}"] : []);
        if ($byCustomer) {
            $this->alertStaff($booking, 'booking_cancelled', 'ألغى العميل حجزًا', "{$booking->number} — {$booking->customer->name} — ".$booking->starts_at->format('Y-m-d H:i'), 'warning');
        }

        return $booking;
    }

    public function noShow(Booking $booking): Booking
    {
        $this->assertOpen($booking);
        DB::transaction(function () use (&$booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($booking);
            $booking->update(['status' => 'no_show']);
            app(BookingDeposits::class)->release($booking);
        });

        return $booking;
    }

    /**
     * Done: a covering prepaid package pays one visit (no invoice); otherwise an invoice is issued
     * (service line, member discount, the deposit already received as a payment). A free booking issues none.
     */
    public function complete(Booking $booking, ?int $packageId = null, bool $usePackage = true): Booking
    {
        $this->assertOpen($booking);

        return DB::transaction(function () use ($booking, $packageId, $usePackage) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($booking);
            $service = $booking->service;
            $package = null;
            if ($usePackage) {
                $package = $this->packages->pick($booking->customer_id, $booking->bookable_service_id, $booking->starts_at, $packageId ?: $booking->customer_package_id);
            }

            $net = round((float) $booking->price - (float) $booking->member_discount, 2);
            if ($package) {
                $this->packages->use($package, $booking);
                $booking->update(['status' => 'completed', 'customer_package_id' => $package->id]);
            } elseif ($net > 0) {
                $invoice = $this->invoices->create([
                    'customer_id' => $booking->customer_id,
                    'branch_id' => $booking->branch_id,
                    'invoice_date' => now()->toDateString(),
                    'supply_date' => $booking->starts_at->toDateString(),
                    'payment_method' => 'credit',
                    'payment_status' => 'unpaid',
                    'reference_number' => $booking->number,
                    'notes' => "حجز {$booking->number} — ".$booking->starts_at->format('Y-m-d H:i'),
                ], [[
                    'product_id' => $service->product_id,
                    'item_name' => $service->name,
                    'description' => "حجز {$booking->number} — ".$booking->starts_at->format('Y-m-d H:i'),
                    'quantity' => 1,
                    'unit_price' => (float) $booking->price,
                    'discount' => (float) $booking->member_discount,
                    'tax_rate' => (float) $service->tax_rate,
                ]], ['issue_stock' => false, 'check_credit' => false]);
                $booking->update(['status' => 'completed', 'invoice_id' => $invoice->id]);
                app(BookingDeposits::class)->apply($booking, $invoice->fresh()); // the deposit received earlier settles it
            } else {
                $booking->update(['status' => 'completed']); // free (100 % member discount)
            }
            if ($package || $net <= 0) {
                app(BookingDeposits::class)->credit($booking); // nothing to settle: the deposit stays the customer's credit
            }

            return $booking;
        });
    }

    protected function assertOpen(Booking $booking): void
    {
        if (! $booking->isOpen()) {
            throw ValidationException::withMessages(['status' => 'الحجز مغلق ولا يمكن تغييره.']);
        }
    }

    /** "Any resource": the least-loaded one that is free at the time. */
    protected function pickResource(BookableService $service, Carbon $start, bool $enforce): BookingResource
    {
        $slot = $this->availability->slots($service, null, $start, $enforce)->firstWhere('time', $start->format('H:i'));
        if (! $slot) {
            throw ValidationException::withMessages(['starts_at' => 'لا يوجد مورد متاح في هذا الموعد.']);
        }

        return BookingResource::findOrFail($slot['resource_id']);
    }

    protected function alertStaff(Booking $booking, string $kind, string $title, string $body, string $level): void
    {
        $url = route('bookings.show', $booking->id, false);
        User::where('company_id', $booking->company_id)->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('bookings.bookings.view'))
            ->each(fn (User $u) => $u->notify(new ErpAlert($kind, $title, $body, $url, $level)));
    }

    /** E-mail the customer (the booking's customer address); a failed mail never blocks the booking. */
    public function tellCustomer(Booking $booking, string $subject, array $extra = []): void
    {
        $email = $booking->customer?->email;
        if (! $email) {
            return;
        }
        $lines = array_merge([
            "الخدمة: {$booking->service->name}",
            'الموعد: '.$booking->starts_at->format('Y-m-d H:i'),
            "الرقم: {$booking->number}",
        ], $extra);
        try {
            Notification::route('mail', $email)->notify(new BookingMessage($subject, $lines));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

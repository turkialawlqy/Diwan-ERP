<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\CustomerPackage;
use App\Models\PackageUsage;
use App\Models\VisitPackage;
use App\Services\Documents\DocumentNumberService;
use App\Services\Sales\InvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Prepaid visit packages: selling them (an invoice), deducting visits and expiring them. */
class PackageService
{
    public function __construct(protected InvoiceService $invoices, protected DocumentNumberService $numbers) {}

    /** Sell a package template to a customer and bill it (credit, or paid when `paid` is true). */
    public function sell(VisitPackage $template, int $customerId, bool $paid = false, ?string $date = null): CustomerPackage
    {
        $date = Carbon::parse($date ?: now());

        return DB::transaction(function () use ($template, $customerId, $paid, $date) {
            $invoice = null;
            if ((float) $template->price > 0) {
                $invoice = $this->invoices->create([
                    'customer_id' => $customerId,
                    'invoice_date' => $date->toDateString(),
                    'payment_method' => $paid ? 'cash' : 'credit',
                    'payment_status' => $paid ? 'paid' : 'unpaid',
                    'notes' => "باقة {$template->name} — {$template->visits} زيارة",
                ], [[
                    'item_name' => $template->name,
                    'description' => "{$template->visits} زيارة",
                    'quantity' => 1,
                    'unit_price' => (float) $template->price,
                    'tax_rate' => (float) $template->tax_rate,
                ]], ['issue_stock' => false, 'check_credit' => false]);
            }

            return CustomerPackage::create([
                'number' => $this->numbers->next('customer_package', $date),
                'customer_id' => $customerId,
                'visit_package_id' => $template->id,
                'bookable_service_id' => $template->bookable_service_id,
                'name' => $template->name,
                'visits_total' => $template->visits,
                'expires_at' => $template->valid_days ? $date->copy()->addDays($template->valid_days)->toDateString() : null,
                'status' => 'active',
                'invoice_id' => $invoice?->id,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /** The package that should pay this booking: the chosen one, else the soonest-expiring covering one. */
    public function pick(int $customerId, int $serviceId, $date, ?int $preferredId = null): ?CustomerPackage
    {
        $query = CustomerPackage::where('customer_id', $customerId)->where('status', 'active');
        if ($preferredId) {
            $chosen = (clone $query)->find($preferredId);
            if (! $chosen || ! $chosen->covers($serviceId, $date)) {
                throw ValidationException::withMessages(['customer_package_id' => 'الباقة المختارة لا تغطي هذا الحجز (رصيد أو صلاحية أو خدمة).']);
            }

            return $chosen;
        }

        return $query->get()->filter(fn (CustomerPackage $p) => $p->covers($serviceId, $date))
            ->sortBy(fn ($p) => $p->expires_at?->timestamp ?? PHP_INT_MAX)->first();
    }

    /** Deduct one visit; never below zero. */
    public function use(CustomerPackage $package, Booking $booking): void
    {
        $fresh = CustomerPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
        if ($fresh->remaining() < 1) {
            throw ValidationException::withMessages(['customer_package_id' => 'لا رصيد زيارات في هذه الباقة.']);
        }
        $fresh->visits_used++;
        if ($fresh->remaining() === 0) {
            $fresh->status = 'used_up';
        }
        $fresh->save();
        PackageUsage::create(['customer_package_id' => $fresh->id, 'booking_id' => $booking->id, 'delta' => -1, 'note' => "حجز {$booking->number}"]);
    }

    /** Deduct one visit for a walk-in (a gym check-in) with no booking. */
    public function useForCheckin(CustomerPackage $package, string $note): void
    {
        DB::transaction(function () use ($package, $note) {
            $fresh = CustomerPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
            if ($fresh->remaining() < 1) {
                throw ValidationException::withMessages(['customer_package_id' => 'لا رصيد زيارات في هذه الباقة.']);
            }
            $fresh->visits_used++;
            if ($fresh->remaining() === 0) {
                $fresh->status = 'used_up';
            }
            $fresh->save();
            PackageUsage::create(['customer_package_id' => $fresh->id, 'delta' => -1, 'note' => $note]);
        });
    }

    /** Give a visit back (staff correction). */
    public function giveBack(CustomerPackage $package, ?string $note = null): void
    {
        DB::transaction(function () use ($package, $note) {
            $fresh = CustomerPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
            if ($fresh->visits_used < 1) {
                throw ValidationException::withMessages(['visits' => 'لا زيارات مستعملة لإرجاعها.']);
            }
            $fresh->visits_used--;
            if ($fresh->status === 'used_up' && (! $fresh->expires_at || $fresh->expires_at->gte(today()))) {
                $fresh->status = 'active';
            }
            $fresh->save();
            PackageUsage::create(['customer_package_id' => $fresh->id, 'delta' => 1, 'note' => $note ?: 'إرجاع زيارة']);
        });
    }

    /** Mark lapsed packages. */
    public function expire(): int
    {
        return CustomerPackage::where('status', 'active')->whereDate('expires_at', '<', today())->update(['status' => 'expired']);
    }
}

<?php

namespace App\Services\Sectors;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerSubscription;
use App\Models\MemberCheckin;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Bookings\PackageService;
use Illuminate\Support\Facades\DB;

/** Reception check-in: is this member allowed in (live subscription or a covering visit package)? Every try is logged. */
class GymCheckinService
{
    public function __construct(protected PackageService $packages) {}

    /**
     * @return array{allowed:bool,reason:?string,customer:?array,subscription:?array,package:?array}
     */
    public function scan(string $code, ?User $by = null, string $method = 'scan'): array
    {
        $code = trim($code);
        $customer = $code === '' ? null : Customer::query()->where(fn ($q) => $q->whereRaw('lower(code) = ?', [mb_strtolower($code)])->orWhere('phone', $code))->first();

        if (! $customer) {
            $this->log(null, null, null, $code, $method, false, 'الكود غير معروف', $by);

            return ['allowed' => false, 'reason' => 'الكود غير معروف', 'customer' => null, 'subscription' => null, 'package' => null];
        }

        // A membership or package is the customer's, valid in every branch.
        $subs = CustomerSubscription::withoutGlobalScope('branch')->with(['plan' => fn ($q) => $q->withoutGlobalScope('branch')->select('id', 'name', 'is_membership')])
            ->where('customer_id', $customer->id)->get()
            ->filter(fn ($s) => $s->plan && ($s->plan->is_membership || ! $this->hasMembershipPlans() && ! in_array($s->plan->name, LeaseService::PLAN_NAMES, true)))->values();
        $live = $subs->first(fn ($s) => in_array($s->status, ['trial', 'active'], true));

        // Already let in today: in again without using another visit.
        $today = MemberCheckin::withoutGlobalScope('branch')->where('customer_id', $customer->id)->where('allowed', true)->whereNotNull('customer_package_id')
            ->onDay(today())->latest('id')->first(); // a visit already taken from a package today
        if (! $live && $today) {
            $this->log($customer->id, $today->subscription_id, $today->customer_package_id, $code, $method, true, 'دخول ثانٍ اليوم (لا يُخصم)', $by);

            return $this->result(true, null, $customer, null, $today->customer_package_id ? CustomerPackage::withoutGlobalScope('branch')->find($today->customer_package_id) : null);
        }
        if ($live) {
            $this->log($customer->id, $live->id, null, $code, $method, true, null, $by);

            return $this->result(true, null, $customer, $live, null);
        }

        // Only a general package (not one bought for a particular service, like personal training).
        $package = CustomerPackage::withoutGlobalScope('branch')->where('customer_id', $customer->id)->where('status', 'active')->get()
            ->first(fn ($p) => ! $p->bookable_service_id && ($p->covers(null, today()) || ($p->remaining() > 0 && (! $p->expires_at || $p->expires_at->gte(today())))));
        if ($package) {
            $used = DB::transaction(function () use ($package, $customer, $code, $method, $by) {
                // The package row is locked first: two scans of the same card at once wait here in turn (instead of
                // deadlocking on the check-in row's key to it), and the second one sees the visit the first took.
                CustomerPackage::withoutGlobalScope('branch')->whereKey($package->id)->lockForUpdate()->first();
                $taken = MemberCheckin::withoutGlobalScope('branch')->where('customer_id', $customer->id)->where('allowed', true)->whereNotNull('customer_package_id')
                    ->onDay(today())->latest('id')->first();
                if ($taken) {
                    $this->log($customer->id, $taken->subscription_id, $taken->customer_package_id, $code, $method, true, 'دخول ثانٍ اليوم (لا يُخصم)', $by);

                    return $taken->customer_package_id;
                }
                $this->log($customer->id, null, $package->id, $code, $method, true, null, $by); // first: refuses in «all branches» before a visit is used
                $this->packages->useForCheckin($package, 'دخول النادي');

                return $package->id;
            });

            return $this->result(true, null, $customer, null, CustomerPackage::withoutGlobalScope('branch')->find($used));
        }

        $reason = $this->denial($subs);
        $this->log($customer->id, $subs->first()?->id, null, $code, $method, false, $reason, $by);

        return $this->result(false, $reason, $customer, $subs->first(), null);
    }

    /** Check-ins of a day with the counts. */
    public function day(string $date): array
    {
        $rows = MemberCheckin::with('customer:id,name,code')->onDay($date)->orderByDesc('checked_in_at')->get();

        return [
            'allowed' => $rows->where('allowed', true)->count(), 'denied' => $rows->where('allowed', false)->count(), 'unique' => $rows->where('allowed', true)->pluck('customer_id')->unique()->count(),
            'rows' => $rows->map(fn ($r) => ['id' => $r->id, 'time' => $r->checked_in_at->format('H:i'), 'name' => $r->customer?->name ?? '—', 'code' => $r->customer?->code ?? $r->code_entered, 'allowed' => $r->allowed, 'reason' => $r->reason])->values(),
        ];
    }

    protected function denial($subs): string
    {
        $status = $subs->pluck('status');
        if ($status->contains('paused')) {
            return 'العضوية موقوفة (مجمَّدة)';
        }
        if ($status->contains('past_due')) {
            return 'العضوية متعثرة: يوجد إيجار/رسوم متأخرة السداد';
        }
        if ($status->isNotEmpty()) {
            return 'العضوية منتهية أو ملغاة';
        }

        return 'لا عضوية فعّالة ولا باقة زيارات';
    }

    protected function result(bool $allowed, ?string $reason, Customer $customer, ?CustomerSubscription $sub, ?CustomerPackage $package): array
    {
        return [
            'allowed' => $allowed, 'reason' => $reason, 'customer' => ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code],
            'subscription' => $sub ? ['plan' => $sub->plan?->name, 'status' => $sub->status, 'renews_on' => $sub->recurring?->next_run_date?->toDateString()] : null,
            'package' => $package ? ['name' => $package->name, 'remaining' => $package->remaining(), 'expires_at' => $package->expires_at?->toDateString()] : null,
        ];
    }

    protected function hasMembershipPlans(): bool
    {
        return ServicePlan::withoutGlobalScope('branch')->where('is_membership', true)->exists();
    }

    protected function log(?int $customerId, ?int $subId, ?int $packageId, string $code, string $method, bool $allowed, ?string $reason, ?User $by): void
    {
        MemberCheckin::create(['customer_id' => $customerId, 'subscription_id' => $subId, 'customer_package_id' => $packageId, 'code_entered' => $code, 'method' => $method,
            'allowed' => $allowed, 'reason' => $reason, 'checked_in_at' => now(), 'checked_by' => $by?->id]);
    }
}

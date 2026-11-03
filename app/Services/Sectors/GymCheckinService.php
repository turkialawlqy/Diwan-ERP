<?php

namespace App\Services\Sectors;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerSubscription;
use App\Models\MemberCheckin;
use App\Models\User;
use App\Services\Bookings\PackageService;

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

        $subs = CustomerSubscription::with('plan:id,name')->where('customer_id', $customer->id)->get();
        $live = $subs->first(fn ($s) => in_array($s->status, ['trial', 'active'], true));
        if ($live) {
            $this->log($customer->id, $live->id, null, $code, $method, true, null, $by);

            return $this->result(true, null, $customer, $live, null);
        }

        $package = CustomerPackage::where('customer_id', $customer->id)->where('status', 'active')->get()->first(fn ($p) => $p->covers(null, today()) || ($p->remaining() > 0 && (! $p->expires_at || $p->expires_at->gte(today()))));
        if ($package) {
            $this->packages->useForCheckin($package, 'دخول النادي');
            $this->log($customer->id, null, $package->id, $code, $method, true, null, $by);

            return $this->result(true, null, $customer, null, $package->fresh());
        }

        $reason = $this->denial($subs);
        $this->log($customer->id, $subs->first()?->id, null, $code, $method, false, $reason, $by);

        return $this->result(false, $reason, $customer, $subs->first(), null);
    }

    /** Check-ins of a day with the counts. */
    public function day(string $date): array
    {
        $rows = MemberCheckin::with('customer:id,name,code')->whereDate('checked_in_at', $date)->orderByDesc('checked_in_at')->get();

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

    protected function log(?int $customerId, ?int $subId, ?int $packageId, string $code, string $method, bool $allowed, ?string $reason, ?User $by): void
    {
        MemberCheckin::create(['customer_id' => $customerId, 'subscription_id' => $subId, 'customer_package_id' => $packageId, 'code_entered' => $code, 'method' => $method,
            'allowed' => $allowed, 'reason' => $reason, 'checked_in_at' => now(), 'checked_by' => $by?->id]);
    }
}

<?php

namespace App\Services\Sectors;

use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Lease;
use App\Models\PropertyUnit;
use App\Models\ServicePlan;
use App\Services\Contracts\ContractService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Tax\TaxProfile;
use App\Support\BranchScopeSwitch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A lease = a lease contract + a rent subscription (the existing recurring runner issues the rent invoices)
 * + the unit going occupied; ending it cancels billing, terminates the contract and frees the unit.
 */
class LeaseService
{
    public const PLAN_NAMES = ['monthly' => 'إيجار شهري', 'quarterly' => 'إيجار ربع سنوي', 'yearly' => 'إيجار سنوي'];

    public function __construct(protected ContractService $contracts, protected SubscriptionService $subscriptions, protected DocumentNumberService $numbers) {}

    /** @param array $data customer_id, property_unit_id, start_date, end_date, rent_amount, cycle, security_deposit?, template_id? */
    public function create(array $data): Lease
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_date' => 'نهاية العقد يجب أن تكون بعد بدايته.']);
        }
        if ((float) $data['rent_amount'] <= 0) {
            throw ValidationException::withMessages(['rent_amount' => 'أدخل قيمة الإيجار.']);
        }

        return DB::transaction(function () use ($data, $start, $end) {
            $unit = PropertyUnit::with('property')->whereKey($data['property_unit_id'])->lockForUpdate()->firstOrFail();
            if ($unit->status !== 'vacant') {
                throw ValidationException::withMessages(['property_unit_id' => 'الوحدة '.(PropertyUnit::STATUSES[$unit->status] ?? $unit->status).' ولا يمكن تأجيرها الآن.']);
            }

            $cycle = $data['cycle'];
            $plan = $this->rentPlan($cycle);
            $months = max(1, (int) round($start->diffInMonths($end->copy()->addDay())));
            $template = ! empty($data['template_id']) ? ContractTemplate::find($data['template_id']) : null;

            $subscription = $this->subscriptions->start([
                'customer_id' => $data['customer_id'], 'service_plan_id' => $plan->id, 'quantity' => 1, 'unit_price' => $data['rent_amount'], 'start_date' => $start->toDateString(),
                'line_description' => 'إيجار '.$unit->label(), 'notes' => 'عقد إيجار — '.$unit->label(),
                'end_date' => $end->copy()->addDay()->toDateString(), 'branch_id' => $unit->branch_id, // billing stops at the lease end; endExpired() closes it
            ]);

            $contract = $this->contracts->create([
                'title' => 'عقد إيجار — '.$unit->label(), 'kind' => 'lease', 'party_type' => 'customer', 'customer_id' => $data['customer_id'], 'template_id' => $template?->id,
                'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'value' => round((float) $data['rent_amount'] * $this->periods($cycle, $months), 2),
                'payment_terms' => 'إيجار '.(Lease::CYCLES[$cycle] ?? $cycle).' بقيمة '.number_format((float) $data['rent_amount'], 2), 'body' => $template?->body, 'subscription_id' => $subscription->id,
            ]);
            $this->contracts->submit($contract);

            $lease = Lease::create([
                'number' => $this->numbers->next('lease', $start), 'property_unit_id' => $unit->id, 'customer_id' => $data['customer_id'], 'contract_id' => $contract->id,
                'subscription_id' => $subscription->id, 'start_date' => $start, 'end_date' => $end, 'rent_amount' => $data['rent_amount'], 'cycle' => $cycle,
                'security_deposit' => $data['security_deposit'] ?? 0, 'status' => 'active', 'created_by' => auth()->id(),
            ]);
            $unit->update(['status' => 'occupied']);

            return $lease;
        });
    }

    /** End the lease: stop the rent billing, terminate the contract, free the unit. */
    public function end(Lease $lease, ?string $reason = null): Lease
    {
        if ($lease->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'العقد منتهٍ بالفعل.']);
        }

        return DB::transaction(function () use ($lease, $reason) {
            if ($lease->subscription && $lease->subscription->isLive()) {
                $this->subscriptions->cancel($lease->subscription, $reason ?: 'انتهاء عقد الإيجار', false);
            }
            if ($lease->contract && in_array($lease->contract->status, ['approved', 'active'], true)) {
                $this->contracts->terminate($lease->contract, $reason ?: 'انتهاء عقد الإيجار');
            }
            $lease->update(['status' => 'ended', 'ended_on' => today(), 'end_reason' => $reason]);
            $lease->unit->update(['status' => 'vacant']);

            return $lease;
        });
    }

    /**
     * The rent plan of a cycle: one shared plan the system owns (no trial, active, price set per lease).
     * A plan the user renamed, switched off or gave a trial is never reused.
     */
    protected function rentPlan(string $cycle): ServicePlan
    {
        return BranchScopeSwitch::off(function () use ($cycle) {
            $plan = ServicePlan::where('name', self::PLAN_NAMES[$cycle])->where('cycle', $cycle)->where('interval', 1)
                ->where('is_active', true)->where('trial_days', 0)->whereNull('branch_id')->first();

            return $plan ?? ServicePlan::create(['name' => self::PLAN_NAMES[$cycle], 'code' => $this->numbers->next('service_plan'), 'price' => 0, 'tax_rate' => TaxProfile::rate(),
                'cycle' => $cycle, 'interval' => 1, 'trial_days' => 0, 'is_active' => true, 'branch_id' => null]);
        });
    }

    /** Nightly: leases past their end date end on their own (billing already stopped at the end date). */
    public function endExpired(): int
    {
        $n = 0;
        Lease::where('status', 'active')->whereDate('end_date', '<', today())->orderBy('id')->chunkById(200, function ($leases) use (&$n) {
            foreach ($leases as $lease) {
                $this->end($lease, 'انتهاء مدة عقد الإيجار');
                $n++;
            }
        });

        return $n;
    }

    /** Invoices issued so far for the lease. */
    public function invoices(Lease $lease)
    {
        return $lease->subscription ? $this->subscriptions->invoices($lease->subscription) : collect();
    }

    protected function periods(string $cycle, int $months): int
    {
        return max(1, (int) ceil($months / ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$cycle]));
    }
}

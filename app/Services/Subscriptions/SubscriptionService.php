<?php

namespace App\Services\Subscriptions;

use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRun;
use App\Models\ServicePlan;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Documents\DocumentNumberService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\RecurringInvoiceRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of a customer subscription. The subscription owns a recurring
 * invoice template, so every invoice (tax, ZATCA, branches, e-mailing) comes
 * from the existing recurring-invoice machinery; this class only starts,
 * pauses, changes and ends it, and keeps the subscription status in step.
 */
class SubscriptionService
{
    /** Days an unpaid subscription invoice may stay overdue before the subscription is «متعثر». */
    public const GRACE_DAYS = 7;

    /** Renewal reminder steps (days before the renewal), like the platform's own subscriptions. */
    public const REMINDER_DAYS = [7, 3, 0];

    public function __construct(
        protected DocumentNumberService $numbers,
        protected InvoiceService $invoices,
        protected RecurringInvoiceRunner $runner,
    ) {}

    /**
     * Start a subscription: trial first when the plan has one, billing from the start (or trial end) date.
     * The first invoice is issued at once when billing starts today or earlier.
     */
    public function start(array $data): CustomerSubscription
    {
        $plan = ServicePlan::findOrFail($data['service_plan_id']);
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['service_plan_id' => 'هذه الخطة غير نشطة.']);
        }

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $quantity = (float) ($data['quantity'] ?? 1);
        $price = isset($data['unit_price']) && $data['unit_price'] !== '' ? (float) $data['unit_price'] : (float) $plan->price;
        $trialEnd = $plan->trial_days > 0 ? $start->copy()->addDays($plan->trial_days) : null;
        $billFrom = $trialEnd ?? $start;

        $subscription = DB::transaction(function () use ($data, $plan, $start, $quantity, $price, $trialEnd, $billFrom) {
            $customer = Customer::findOrFail($data['customer_id']);
            $template = RecurringInvoice::create([
                'customer_id' => $customer->id, 'branch_id' => $data['branch_id'] ?? null,
                'name' => 'اشتراك: '.$plan->name.' — '.$customer->name,
                'lines' => [$this->line($plan, $quantity, $price, (float) $plan->tax_rate)],
                'frequency' => $plan->cycle, 'interval' => max(1, (int) $plan->interval),
                'start_date' => $billFrom->toDateString(), 'next_run_date' => $billFrom->toDateString(),
                'invoice_type' => 'standard', 'auto_send' => (bool) ($data['auto_send'] ?? false),
                'status' => 'active', 'created_by' => auth()->id(),
            ]);

            return CustomerSubscription::create([
                'branch_id' => $data['branch_id'] ?? null,
                'number' => $this->numbers->next('subscription'),
                'customer_id' => $customer->id, 'service_plan_id' => $plan->id, 'recurring_invoice_id' => $template->id,
                'quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => $plan->tax_rate,
                'status' => $trialEnd && $trialEnd->gt(today()) ? 'trial' : 'active',
                'start_date' => $start->toDateString(), 'trial_ends_at' => $trialEnd?->toDateString(),
                'auto_renew' => (bool) ($data['auto_renew'] ?? true), 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
        });

        // Bill the first period now rather than waiting for the nightly run.
        if ($subscription->recurring->next_run_date->lte(today())) {
            $this->runner->runTemplate($subscription->recurring);
        }

        return $subscription->fresh(['customer', 'plan', 'recurring']);
    }

    public function pause(CustomerSubscription $subscription): void
    {
        if (! $subscription->isLive()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن إيقاف اشتراك '.CustomerSubscription::STATUSES[$subscription->status].'.']);
        }
        $subscription->recurring?->update(['status' => 'paused']);
        $subscription->update(['status' => 'paused']);
    }

    public function resume(CustomerSubscription $subscription): void
    {
        if ($subscription->status !== 'paused') {
            throw ValidationException::withMessages(['status' => 'الاشتراك غير موقوف.']);
        }
        // The paused time is not billed: the next invoice is not earlier than today.
        $template = $subscription->recurring;
        $template?->update(['status' => 'active', 'next_run_date' => max($template->next_run_date, today())->toDateString()]);
        $subscription->update(['status' => $this->hasPendingTrial($subscription) ? 'trial' : 'active']);
    }

    public function cancel(CustomerSubscription $subscription, ?string $reason, bool $atPeriodEnd): void
    {
        if (in_array($subscription->status, ['cancelled', 'expired'], true)) {
            throw ValidationException::withMessages(['status' => 'الاشتراك منتهٍ أصلًا.']);
        }

        if ($atPeriodEnd && $subscription->status !== 'paused') {
            $subscription->update(['cancel_at_period_end' => true, 'auto_renew' => false, 'cancel_reason' => $reason]);

            return;
        }
        $this->end($subscription, 'cancelled', $reason);
    }

    /** Undo «إلغاء في نهاية الفترة» before it takes effect. */
    public function keep(CustomerSubscription $subscription): void
    {
        $subscription->update(['cancel_at_period_end' => false, 'auto_renew' => true, 'cancel_reason' => null]);
    }

    /**
     * Move to another plan. An upgrade «now» bills the pro-rata difference for the rest of the paid period
     * at once; a downgrade (or $when = 'renewal') takes effect at the next renewal.
     *
     * @return Invoice|null the pro-rata invoice, if one was issued
     */
    public function changePlan(CustomerSubscription $subscription, ServicePlan $plan, string $when = 'now'): ?Invoice
    {
        if (! $subscription->isLive() && $subscription->status !== 'paused') {
            throw ValidationException::withMessages(['status' => 'لا يمكن تغيير خطة اشتراك منتهٍ.']);
        }
        if ($plan->id === $subscription->service_plan_id) {
            throw ValidationException::withMessages(['service_plan_id' => 'الاشتراك على هذه الخطة بالفعل.']);
        }

        $newPrice = (float) $plan->price;
        $downgrade = $newPrice * (float) $subscription->quantity < (float) $subscription->unit_price * (float) $subscription->quantity - 0.005;
        if ($when === 'renewal' || $downgrade) {
            $subscription->update(['pending_plan_id' => $plan->id]);

            return null;
        }

        return DB::transaction(function () use ($subscription, $plan, $newPrice) {
            $invoice = null;
            $template = $subscription->recurring;

            // Something is already paid for the current period: bill the difference for what is left of it.
            if ($template && $template->generated_count > 0) {
                $periodEnd = $template->next_run_date->copy();
                $periodStart = $periodEnd->copy()->subMonthsNoOverflow($this->months($template));
                $days = max(1, $periodStart->diffInDays($periodEnd));
                $left = max(0, min($days, today()->diffInDays($periodEnd, false)));
                $oldDaily = (float) $subscription->unit_price / $days;
                $newDaily = $newPrice / $days;
                $amount = round(($newDaily - $oldDaily) * $left * (float) $subscription->quantity, 2);

                if ($amount > 0.004) {
                    $invoice = $this->invoices->create([
                        'customer_id' => $subscription->customer_id, 'branch_id' => $subscription->branch_id,
                        'invoice_date' => today()->toDateString(), 'supply_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
                        'invoice_type' => 'standard', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
                        'notes' => 'فرق ترقية الخطة من «'.$subscription->plan->name.'» إلى «'.$plan->name.'» عن '.$left.' يوم — '.$subscription->number,
                    ], [['product_id' => $plan->product_id, 'item_name' => 'فرق ترقية الخطة: '.$plan->name, 'quantity' => 1, 'unit_price' => $amount, 'discount' => 0, 'tax_rate' => (float) $plan->tax_rate]],
                        ['issue_stock' => false, 'check_credit' => false]);
                }
            }

            $this->applyPlan($subscription, $plan, $newPrice);

            return $invoice;
        });
    }

    /** Apply a plan change that was waiting for the renewal. */
    public function applyPending(CustomerSubscription $subscription): bool
    {
        $plan = $subscription->pendingPlan;
        if (! $plan) {
            return false;
        }
        $this->applyPlan($subscription, $plan, (float) $plan->price);

        return true;
    }

    /** Date of the next invoice (the renewal). */
    public function renewsOn(CustomerSubscription $subscription): ?Carbon
    {
        return $subscription->isLive() || $subscription->status === 'paused' ? $subscription->recurring?->next_run_date : null;
    }

    /** Invoices issued for the subscription, newest first. */
    public function invoices(CustomerSubscription $subscription)
    {
        $ids = RecurringInvoiceRun::where('recurring_invoice_id', $subscription->recurring_invoice_id)->whereNotNull('invoice_id')->pluck('invoice_id');

        return Invoice::whereIn('id', $ids)->latest('invoice_date')->get(['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount', 'remaining_amount', 'payment_status']);
    }

    /** Monthly recurring revenue (net of tax) of the live subscriptions. */
    public function mrr(): float
    {
        return round(CustomerSubscription::with('plan')->whereIn('status', ['active', 'past_due'])->get()
            ->sum(fn ($s) => (float) $s->quantity * $s->plan->monthlyEquivalent((float) $s->unit_price)), 2);
    }

    /**
     * Nightly housekeeping, before the recurring-invoice run: trials that ended start billing,
     * plan changes due at renewal are applied, subscriptions that do not renew end, unpaid ones
     * become «متعثر» (and recover when paid), and renewal reminders go out.
     *
     * @return array<string,int> counters per action
     */
    public function nightly(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $done = ['trial_ended' => 0, 'plan_changed' => 0, 'ended' => 0, 'past_due' => 0, 'recovered' => 0, 'reminders' => 0];

        CustomerSubscription::with(['recurring', 'plan', 'customer:id,name'])->whereIn('status', ['trial', 'active', 'past_due'])->orderBy('id')->each(function ($s) use ($today, &$done) {
            $renews = $s->recurring?->next_run_date;

            if ($s->status === 'trial' && $s->trial_ends_at && $s->trial_ends_at->lte($today)) {
                $s->update(['status' => 'active']);
                $done['trial_ended']++;
            }

            if ($renews && $renews->lte($today)) {
                if ($s->cancel_at_period_end || ! $s->auto_renew) {
                    $this->end($s, $s->cancel_at_period_end ? 'cancelled' : 'expired', $s->cancel_reason);
                    $done['ended']++;

                    return;
                }
                if ($this->applyPending($s)) {
                    $done['plan_changed']++;
                }
            }

            $done['past_due'] += $this->syncPastDue($s, $today, $done);
            $done['reminders'] += $this->remind($s->fresh(['recurring', 'plan', 'customer:id,name']), $today);
        });

        return $done;
    }

    // ------------------------------------------------------------------

    protected function end(CustomerSubscription $subscription, string $status, ?string $reason): void
    {
        $subscription->recurring?->update(['status' => 'ended']);
        $subscription->update(['status' => $status, 'cancelled_at' => today()->toDateString(), 'cancel_reason' => $reason, 'cancel_at_period_end' => false, 'pending_plan_id' => null]);
    }

    protected function applyPlan(CustomerSubscription $subscription, ServicePlan $plan, float $price): void
    {
        $template = $subscription->recurring;
        $template?->update([
            'lines' => [$this->line($plan, (float) $subscription->quantity, $price, (float) $plan->tax_rate)],
            'frequency' => $plan->cycle, 'interval' => max(1, (int) $plan->interval),
            'name' => 'اشتراك: '.$plan->name.' — '.$subscription->customer?->name,
        ]);
        $subscription->update(['service_plan_id' => $plan->id, 'unit_price' => $price, 'tax_rate' => $plan->tax_rate, 'pending_plan_id' => null]);
    }

    protected function line(ServicePlan $plan, float $quantity, float $price, float $taxRate): array
    {
        return ['product_id' => $plan->product_id, 'item_name' => $plan->name, 'quantity' => $quantity, 'unit_price' => $price, 'discount' => 0, 'tax_rate' => $taxRate];
    }

    protected function months(RecurringInvoice $template): int
    {
        return RecurringInvoice::MONTHS[$template->frequency] * max(1, (int) $template->interval);
    }

    protected function hasPendingTrial(CustomerSubscription $subscription): bool
    {
        return $subscription->trial_ends_at && $subscription->trial_ends_at->gt(today());
    }

    /** Mark / unmark «متعثر» from the latest unpaid invoice. Returns 1 when newly marked. */
    protected function syncPastDue(CustomerSubscription $s, Carbon $today, array &$done): int
    {
        $latest = $this->invoices($s)->first();
        $overdue = $latest && $latest->payment_status !== 'paid' && $latest->due_date
            && $latest->due_date->copy()->addDays(self::GRACE_DAYS)->lt($today);

        if ($overdue && $s->status === 'active') {
            $s->update(['status' => 'past_due']);
            $this->notify($s, 'subscription_past_due', 'اشتراك متعثر: '.$s->customer?->name, "الفاتورة {$latest->invoice_number} لاشتراك {$s->number} متأخرة عن السداد.", 'danger');

            return 1;
        }
        if (! $overdue && $s->status === 'past_due') {
            $s->update(['status' => 'active']);
            $done['recovered']++;
        }

        return 0;
    }

    protected function remind(CustomerSubscription $s, Carbon $today): int
    {
        $renews = $s->recurring?->next_run_date;
        if (! $renews || $s->status === 'paused') {
            return 0;
        }
        $days = (int) $today->diffInDays($renews, false);
        if (! in_array($days, self::REMINDER_DAYS, true)) {
            return 0;
        }
        $key = $renews->toDateString().':d'.$days;
        if ($s->reminder_state === $key) {
            return 0;
        }

        $ends = $s->cancel_at_period_end || ! $s->auto_renew;
        $when = $days === 0 ? 'اليوم' : "بعد {$days} يوم";
        $title = ($ends ? 'ينتهي اشتراك ' : 'يتجدد اشتراك ').$s->customer?->name.' '.$when;
        $body = $ends ? "الاشتراك {$s->number} ({$s->plan->name}) لن يتجدد في ".$renews->toDateString().'.'
            : "الاشتراك {$s->number} ({$s->plan->name}) سيُجدَّد في ".$renews->toDateString().' بقيمة '.number_format($s->periodTotal(), 2).'.';
        $this->notify($s, 'subscription_renewal', $title, $body, $ends ? 'warning' : 'info');
        $s->update(['reminder_state' => $key]);

        return 1;
    }

    protected function notify(CustomerSubscription $s, string $kind, string $title, string $body, string $level): void
    {
        $url = route('sales.subscriptions.show', $s->id, false);
        User::where('company_id', $s->company_id)->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('sales.subscriptions.view'))
            ->each(fn (User $u) => $u->notify(new ErpAlert($kind, $title, $body, $url, $level)));
    }
}

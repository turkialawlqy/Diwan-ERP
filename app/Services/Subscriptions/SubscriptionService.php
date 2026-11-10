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
use App\Support\NightlyRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
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
                'lines' => [array_merge($this->line($plan, $quantity, $price, (float) $plan->tax_rate), ! empty($data['line_description']) ? ['description' => $data['line_description']] : [], $data['line_extra'] ?? [])],
                'frequency' => $plan->cycle, 'interval' => max(1, (int) $plan->interval),
                'start_date' => $billFrom->toDateString(), 'next_run_date' => $billFrom->toDateString(),
                // A fixed term (a lease): no period starts on or after the last day.
                'end_date' => ! empty($data['end_date']) ? Carbon::parse($data['end_date'])->subDay()->toDateString() : null,
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
        // The paused time is not billed. Resumed before the paid period ran out: nothing changes. Resumed after it:
        // a new period starts today and the billing day moves to today (else the old day of the month would bill
        // a few days later as if a whole period had passed).
        $template = $subscription->recurring;
        if ($template && $template->next_run_date->lt(today())) {
            $template->update(['status' => 'active', 'start_date' => today()->toDateString(), 'next_run_date' => today()->toDateString()]);
        } else {
            $template?->update(['status' => 'active']);
        }
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
        if (! $subscription->isLive() || ! $subscription->cancel_at_period_end) {
            throw ValidationException::withMessages(['status' => 'لا يوجد إلغاء مجدول لهذا الاشتراك.']);
        }
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
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['service_plan_id' => 'هذه الخطة غير نشطة.']);
        }
        if ($plan->id === $subscription->service_plan_id) {
            throw ValidationException::withMessages(['service_plan_id' => 'الاشتراك على هذه الخطة بالفعل.']);
        }

        $newPrice = (float) $plan->price;
        $template = $subscription->recurring;
        $oldMonths = $template ? $this->months($template) : $subscription->plan->months();
        // Compared per month, so a yearly plan is not «more expensive» than a monthly one just for covering 12 months.
        $downgrade = $newPrice / $plan->months() < (float) $subscription->unit_price / $oldMonths - 0.005;
        if ($when === 'renewal' || $downgrade) {
            $subscription->update(['pending_plan_id' => $plan->id]);

            return null;
        }

        return DB::transaction(function () use ($subscription, $plan, $newPrice, $template, $oldMonths) {
            $invoice = null;
            $paid = $template && $template->generated_count > 0 && $subscription->status !== 'trial';

            if ($paid && $plan->months() === $oldMonths) {
                // Same cycle: bill the difference for what is left of the paid period; the renewal date stays.
                [$days, $left] = $this->leftOfPeriod($template, $oldMonths);
                $amount = round(($newPrice - (float) $subscription->unit_price) / $days * $left * (float) $subscription->quantity, 2);
                if ($amount > 0.004) {
                    $invoice = $this->bill($subscription, $plan, [['product_id' => $plan->product_id, 'item_name' => 'فرق ترقية الخطة: '.$plan->name, 'quantity' => 1, 'unit_price' => $amount, 'discount' => 0, 'tax_rate' => (float) $plan->tax_rate]],
                        'فرق ترقية الخطة من «'.$subscription->plan->name.'» إلى «'.$plan->name.'» عن '.$left.' يوم — '.$subscription->number);
                }
                $this->applyPlan($subscription, $plan, $newPrice);

                return $invoice;
            }

            if ($paid) {
                // Another cycle: the new plan's first period starts today, less what is left unused of the paid one.
                [$days, $left] = $this->leftOfPeriod($template, $oldMonths);
                $credit = round((float) $subscription->unit_price / $days * $left * (float) $subscription->quantity, 2);
                $gross = round($newPrice * (float) $subscription->quantity, 2);
                $this->applyPlan($subscription, $plan, $newPrice);
                $invoice = $this->bill($subscription, $plan, [['product_id' => $plan->product_id, 'item_name' => $plan->name, 'quantity' => (float) $subscription->quantity, 'unit_price' => $newPrice,
                    'discount' => min($gross, $credit), 'tax_rate' => (float) $plan->tax_rate]],
                    'بداية الخطة «'.$plan->name.'» مع خصم رصيد '.$left.' يوم غير مستخدمة من الخطة السابقة — '.$subscription->number, today());
                $template->refresh();
                $template->start_date = today(); // the billing day moves to today first, then the next date follows it
                $template->next_run_date = $template->nextAfter(today());
                $template->generated_count = $template->generated_count + 1;
                $template->save();

                return $invoice;
            }

            $this->applyPlan($subscription, $plan, $newPrice);

            return null;
        });
    }

    /** [days in the current paid period, days of it still ahead]. */
    protected function leftOfPeriod(RecurringInvoice $template, int $months): array
    {
        $periodEnd = $template->next_run_date->copy();
        $periodStart = $periodEnd->copy()->subMonthsNoOverflow($months);
        $days = max(1, $periodStart->diffInDays($periodEnd));

        return [$days, (int) max(0, min($days, today()->diffInDays($periodEnd, false)))];
    }

    /**
     * An invoice the subscription issues outside the regular run (upgrade). With $period it is the
     * first invoice of a new period and is recorded as that period's run, so it lists with the rest.
     */
    protected function bill(CustomerSubscription $subscription, ServicePlan $plan, array $lines, string $notes, ?Carbon $period = null): Invoice
    {
        $invoice = $this->invoices->create([
            'customer_id' => $subscription->customer_id, 'branch_id' => $subscription->branch_id,
            'invoice_date' => today()->toDateString(), 'supply_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
            'invoice_type' => 'standard', 'payment_method' => 'credit', 'payment_status' => 'unpaid', 'notes' => $notes,
        ], $lines, ['issue_stock' => false, 'check_credit' => false]);
        // Linked to the subscription like its regular invoices (shown on its page and in the portal, counted for «متعثر»).
        $invoice->forceFill(['customer_subscription_id' => $subscription->id])->save();
        if ($period && ! RecurringInvoiceRun::where('recurring_invoice_id', $subscription->recurring_invoice_id)->whereDate('period_date', $period)->exists()) {
            RecurringInvoiceRun::create(['recurring_invoice_id' => $subscription->recurring_invoice_id, 'period_date' => $period->toDateString(), 'invoice_id' => $invoice->id]);
        }

        return $invoice;
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

        return Invoice::where(fn ($q) => $q->whereIn('id', $ids)->orWhere('customer_subscription_id', $subscription->id))->latest('invoice_date')->latest('id')->get(['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount', 'remaining_amount', 'payment_status']);
    }

    /** Monthly recurring revenue (net of tax) of the live subscriptions, by the cycle each one is billed on. */
    public function mrr(): float
    {
        return round(CustomerSubscription::with('recurring')->whereIn('status', ['active', 'past_due'])->get()
            ->sum(fn ($s) => (float) $s->quantity * (float) $s->unit_price / max(1, $s->recurring ? $this->months($s->recurring) : 1)), 2);
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
        $done = ['trial_ended' => 0, 'plan_changed' => 0, 'ended' => 0, 'past_due' => 0, 'recovered' => 0, 'reminders' => 0, 'failed' => 0];

        // Each subscription on its own: one that fails is reported and counted, the others still run.
        // Each batch reads its invoices at once: the queries follow the batches, not the subscriptions.
        // 150 stays under MySQL's limit of 200 IN values: past it the plan comes from index statistics and scans the company's invoices.
        CustomerSubscription::with(['recurring', 'plan', 'customer:id,name'])->whereIn('status', ['trial', 'active', 'past_due'])->chunkById(150, function ($batch) use ($today, &$done) {
            $invoices = $this->invoicesOfBatch($batch);

            $batch->each(NightlyRun::step(function ($s) use ($today, &$done, $invoices) {
                $renews = $s->recurring?->next_run_date;

                if ($s->status === 'trial' && $s->trial_ends_at && $s->trial_ends_at->lte($today)) {
                    $s->update(['status' => 'active']);
                    $done['trial_ended']++;
                }

                if ($renews && $renews->lte($today)) {
                    $change = $this->atRenewal($s);
                    if ($change === 'ended') {
                        $done['ended']++;

                        return;
                    }
                    if ($change === 'plan_changed') {
                        $done['plan_changed']++;
                        $s->load('plan'); // the renewal reminder names the new plan
                    }
                }

                $done['past_due'] += $this->syncPastDue($s, $today, $done, $invoices[$s->id] ?? collect());
                $done['reminders'] += $this->remind($s, $today);
            }, $done));
        });

        return $done;
    }

    /**
     * A renewal is due: a subscription set to end there ends, and a plan change waiting for it applies.
     * The nightly run does it, and so does the invoice run when it reaches the renewal first, so the
     * order of the two commands does not matter. The first renewal after a trial is the first paid
     * period, not an «end» (unless cancelled).
     *
     * @return string|null «ended», «plan_changed», or null when it renews as it is
     */
    public function atRenewal(CustomerSubscription $s): ?string
    {
        $firstPaid = $s->recurring && (int) $s->recurring->generated_count === 0 && ! $s->cancel_at_period_end;
        if ($firstPaid) {
            return null;
        }
        if ($s->cancel_at_period_end || ! $s->auto_renew) {
            $this->end($s, $s->cancel_at_period_end ? 'cancelled' : 'expired', $s->cancel_reason);

            return 'ended';
        }

        return $this->applyPending($s) ? 'plan_changed' : null;
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

    /**
     * invoices() for a batch of subscriptions in two queries: subscription id => its invoices, newest first.
     *
     * @param  Collection<int, CustomerSubscription>  $subscriptions
     * @return array<int, SupportCollection>
     */
    protected function invoicesOfBatch(Collection $subscriptions): array
    {
        $runs = RecurringInvoiceRun::whereIn('recurring_invoice_id', $subscriptions->pluck('recurring_invoice_id')->filter()->unique()->all())
            ->whereNotNull('invoice_id')->get(['recurring_invoice_id', 'invoice_id']);
        $invoices = Invoice::where(fn ($q) => $q->whereIn('id', $runs->pluck('invoice_id')->unique()->all())->orWhereIn('customer_subscription_id', $subscriptions->modelKeys()))
            ->latest('invoice_date')->latest('id')->get(['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount', 'remaining_amount', 'payment_status', 'customer_subscription_id']);

        $byTemplate = $subscriptions->groupBy('recurring_invoice_id');
        $templatesOf = $runs->groupBy('invoice_id')->map(fn ($r) => $r->pluck('recurring_invoice_id'));
        $mine = array_fill_keys($subscriptions->modelKeys(), null);
        foreach ($invoices as $invoice) {
            $owners = collect($templatesOf[$invoice->id] ?? [])->flatMap(fn ($template) => $byTemplate[$template] ?? [])->pluck('id');
            if (array_key_exists((int) $invoice->customer_subscription_id, $mine)) {
                $owners->push((int) $invoice->customer_subscription_id);
            }
            foreach ($owners->unique() as $id) {
                $mine[$id] = ($mine[$id] ?? collect())->push($invoice);
            }
        }

        return array_filter($mine);
    }

    /** Mark / unmark «متعثر»: any invoice of the subscription still unpaid past its due date and the grace days. Returns 1 when newly marked. */
    protected function syncPastDue(CustomerSubscription $s, Carbon $today, array &$done, SupportCollection $invoices): int
    {
        $latest = $invoices->filter(fn ($i) => $i->payment_status !== 'paid' && (float) $i->remaining_amount > 0.004 && $i->due_date
            && $i->due_date->copy()->addDays(self::GRACE_DAYS)->lt($today))->sortBy('due_date')->first(); // the oldest one still owed
        $overdue = (bool) $latest;

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

<?php

namespace App\Services\Saas;

use App\Models\Company;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform billing: plan change requests produce a subscription invoice;
 * confirming its payment switches the plan and extends the subscription.
 */
class SubscriptionService
{
    public function request(Company $company, SubscriptionPlan $plan, string $cycle): SubscriptionInvoice
    {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'الباقة غير متاحة.']);
        }
        if (SubscriptionInvoice::where('company_id', $company->id)->where('status', 'unpaid')->exists()) {
            throw ValidationException::withMessages(['plan' => 'لديك فاتورة اشتراك غير مسددة. سددها أو تواصل مع الدعم لإلغائها.']);
        }

        $amount = (float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
        $start = $company->subscription_ends_at && $company->subscription_ends_at->isFuture() && (int) $company->subscription_plan_id === (int) $plan->id
            ? $company->subscription_ends_at->copy()->startOfDay()
            : now()->startOfDay();
        $vat = round($amount * config('saas.vat_rate', 0.15), 2);

        return SubscriptionInvoice::create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'number' => 'SUB-'.now()->format('ym').'-'.strtoupper(Str::random(6)),
            'billing_cycle' => $cycle,
            'period_start' => $start->toDateString(),
            'period_end' => ($cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth())->subDay()->toDateString(),
            'amount' => $amount,
            'vat_amount' => $vat,
            'total' => round($amount + $vat, 2),
            'status' => $amount > 0 ? 'unpaid' : 'paid',
        ]);
    }

    public function confirmPayment(SubscriptionInvoice $invoice, ?string $reference = null, string $method = 'bank_transfer'): void
    {
        DB::transaction(function () use ($invoice, $reference, $method) {
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'unpaid') {
                throw ValidationException::withMessages(['invoice' => 'الفاتورة ليست بانتظار السداد.']);
            }

            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => $reference, 'payment_method' => $method, 'confirmed_by' => auth()->id()]);
            $this->activate($invoice);
        });
    }

    /** Apply a paid invoice to the company. */
    public function activate(SubscriptionInvoice $invoice): void
    {
        $company = Company::lockForUpdate()->findOrFail($invoice->company_id);
        $company->forceFill([
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'billing_cycle' => $invoice->billing_cycle,
            'subscription_ends_at' => $invoice->period_end->copy()->endOfDay(),
            'status' => 'active',
            'suspended_reason' => null,
        ])->save();

        $this->audit('subscription_activated', $company, ['invoice' => $invoice->number, 'plan_id' => $invoice->subscription_plan_id, 'until' => $invoice->period_end->toDateString()]);
    }

    public function audit(string $action, ?Company $company, array $details = []): void
    {
        PlatformAuditLog::create([
            'user_id' => auth()->id(),
            'company_id' => $company?->id,
            'action' => $action,
            'details' => $details,
            'ip' => request()?->ip(),
        ]);
    }
}

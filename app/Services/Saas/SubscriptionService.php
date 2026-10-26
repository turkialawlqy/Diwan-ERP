<?php

namespace App\Services\Saas;

use App\Models\Company;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Services\ZatcaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $issuedAt = now();
        $total = round($amount + $vat, 2);

        // The number is taken and the invoice saved under one lock (no duplicates under concurrency).
        return Cache::lock('subscription-invoice-number', 10)->block(5, fn () => SubscriptionInvoice::create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            // A tax invoice: sequential number and the platform's ZATCA QR.
            'number' => $this->nextNumber(),
            'issued_at' => $issuedAt,
            'qr' => config('saas.platform.tax_number')
                ? ZatcaService::generateQrCode((string) config('saas.platform.name'), (string) config('saas.platform.tax_number'), $issuedAt->toIso8601String(), $total, $vat)
                : null,
            'billing_cycle' => $cycle,
            'period_start' => $start->toDateString(),
            'period_end' => ($cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth())->subDay()->toDateString(),
            'amount' => $amount,
            'vat_amount' => $vat,
            'total' => $total,
            'status' => $amount > 0 ? 'unpaid' : 'paid',
        ]));
    }

    /**
     * SUB-{yy}-000001, sequential per year across the platform. Called under
     * the subscription-invoice-number lock.
     */
    protected function nextNumber(): string
    {
        $prefix = 'SUB-'.now()->format('y').'-';
        $last = SubscriptionInvoice::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $next = $last && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
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

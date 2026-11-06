<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionInvoice;
use App\Services\Saas\Payments\MoyasarGateway;
use App\Services\Saas\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Online payment of the company's own subscription invoices, and the provider's webhook. */
class PaymentController extends Controller
{
    public function checkout(SubscriptionInvoice $invoice, MoyasarGateway $gateway, TenantManager $tenants)
    {
        abort_unless((int) $invoice->company_id === (int) $tenants->getTenantId(), 404);
        if (! $gateway->enabled()) {
            throw ValidationException::withMessages(['payment' => 'الدفع الإلكتروني غير مفعّل، استعمل التحويل البنكي.']);
        }
        if ($invoice->status !== 'unpaid') {
            throw ValidationException::withMessages(['payment' => 'الفاتورة ليست بانتظار السداد.']);
        }

        return response()->json(['url' => $gateway->createCheckout($invoice, route('billing.index'))]);
    }

    public function webhook(Request $request, MoyasarGateway $gateway, SubscriptionService $subscriptions)
    {
        $verified = $gateway->verifyWebhook($request->all());
        if (! $verified) {
            return response()->json(['ok' => false], 400);
        }

        try {
            $subscriptions->confirmPayment(SubscriptionInvoice::findOrFail($verified['invoice_id']), $verified['reference'], 'moyasar');
        } catch (ValidationException) {
            // Already settled (a repeated delivery): acknowledge so the provider stops retrying.
        }

        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Services\Saas\Payments;

use App\Models\SubscriptionInvoice;
use App\Services\Messaging\CompanyMessaging;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Moyasar hosted invoices. NOT verified against the live service: field names follow Moyasar's public API
 * as documented (amounts in halalas, `secret_token` in webhooks); test in their sandbox before enabling.
 */
class MoyasarGateway implements PaymentGateway
{
    public function enabled(): bool
    {
        return (bool) config('saas.payments.moyasar.enabled')
            && filled(config('saas.payments.moyasar.secret_key'))
            && filled(config('saas.payments.moyasar.webhook_token'));
    }

    protected function base(): string
    {
        return rtrim((string) config('saas.payments.moyasar.base_url'), '/');
    }

    protected function http()
    {
        abort_unless(app(CompanyMessaging::class)->safeUrl($this->base()), 500, 'بوابة الدفع غير صالحة.');

        return Http::withBasicAuth((string) config('saas.payments.moyasar.secret_key'), '')->acceptJson()->timeout(20);
    }

    public static function halalas(SubscriptionInvoice $invoice): int
    {
        return (int) round((float) $invoice->total * 100);
    }

    public function createCheckout(SubscriptionInvoice $invoice, string $returnUrl): string
    {
        $response = $this->http()->post($this->base().'/invoices', [
            'amount' => self::halalas($invoice),
            'currency' => 'SAR',
            'description' => 'اشتراك '.$invoice->number,
            'callback_url' => route('webhooks.moyasar'),
            'success_url' => $returnUrl,
            'back_url' => $returnUrl,
            'metadata' => ['subscription_invoice_id' => (string) $invoice->id],
        ]);

        if (! $response->successful() || ! is_string($response['url'] ?? null)) {
            throw ValidationException::withMessages(['payment' => 'تعذّر إنشاء عملية الدفع، حاول لاحقًا أو استعمل التحويل البنكي.']);
        }

        return $response['url'];
    }

    public function verifyWebhook(array $payload): ?array
    {
        $token = (string) config('saas.payments.moyasar.webhook_token');
        if (! $this->enabled() || ! hash_equals($token, (string) ($payload['secret_token'] ?? ''))) {
            return null;
        }

        $paymentId = (string) ($payload['data']['id'] ?? $payload['id'] ?? '');
        if ($paymentId === '' || ! preg_match('/^[A-Za-z0-9\-_]+$/', $paymentId)) {
            return null;
        }

        // Never trust the body alone: read the payment back from Moyasar.
        $response = $this->http()->get($this->base().'/payments/'.$paymentId);
        if (! $response->successful() || ($response['status'] ?? null) !== 'paid') {
            return null;
        }

        $invoice = SubscriptionInvoice::find((int) ($response['metadata']['subscription_invoice_id'] ?? 0));
        if (! $invoice || (int) ($response['amount'] ?? -1) !== self::halalas($invoice) || strtoupper((string) ($response['currency'] ?? '')) !== 'SAR') {
            return null;
        }

        return ['invoice_id' => $invoice->id, 'reference' => $paymentId];
    }
}

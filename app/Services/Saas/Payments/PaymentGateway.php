<?php

namespace App\Services\Saas\Payments;

use App\Models\SubscriptionInvoice;

/** A hosted-checkout provider for platform subscription invoices. */
interface PaymentGateway
{
    public function enabled(): bool;

    /** Creates the provider-side invoice and returns the URL the payer is sent to. */
    public function createCheckout(SubscriptionInvoice $invoice, string $returnUrl): string;

    /**
     * Authenticates a webhook and re-reads the payment from the provider.
     * Returns ['invoice_id' => int, 'reference' => string] only for a settled payment of the right amount; null otherwise.
     */
    public function verifyWebhook(array $payload): ?array;
}

<?php

namespace App\Services\EInvoicing;

use App\Services\Zatca\EInvoiceManager;
use Illuminate\Database\Eloquent\Model;

/** ZATCA «فاتورة»: phase 1 QR, or phase 2 signing + clearance / reporting. */
class ZatcaProvider implements Provider
{
    public function key(): string
    {
        return 'zatca';
    }

    public function label(): string
    {
        return 'منصة «فاتورة» — هيئة الزكاة والضريبة والجمارك';
    }

    public function integrated(): bool
    {
        return true;
    }

    public function stamp(Model $document): void
    {
        app(EInvoiceManager::class)->stampZatca($document);
    }
}

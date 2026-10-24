<?php

namespace App\Services\EInvoicing;

use App\Services\Tax\TaxProfile;

/** The e-invoicing system of a branch's country. */
class ProviderRegistry
{
    public function for(TaxProfile $profile): Provider
    {
        return match ($profile->einvoicing()) {
            'zatca' => new ZatcaProvider,
            default => new PlannedProvider($profile->einvoicing()),
        };
    }
}

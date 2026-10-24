<?php

namespace App\Rules;

use App\Services\Tax\TaxProfile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A tax registration number in the format of the party's country (KSA: 15
 * digits starting and ending with 3; Egypt: 9 digits; ...). The country is
 * the party's own (free text: "مصر", "Egypt", "EG"), else the branch's.
 * Countries outside the catalogue keep their own format.
 */
class TaxNumber implements ValidationRule
{
    public function __construct(protected ?string $country = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $code = TaxProfile::countryCodeFromName($this->country, TaxProfile::current()->countryCode);
        if (! isset(config('countries')[$code])) {
            return;
        }
        $country = config('countries')[$code];
        if (! preg_match($country['tax_number']['pattern'], (string) $value)) {
            $example = $country['tax_number']['example'];
            $fail($country['tax_number']['label'].' غير صحيح لـ'.$country['name'].($example ? " (مثال: {$example})" : '').'.');
        }
    }
}

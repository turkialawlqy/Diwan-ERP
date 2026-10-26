<?php

namespace App\Services\Zatca;

use App\Models\Company;
use App\Models\Product;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the VAT category (S/Z/E/O) and, in Saudi Arabia, the ZATCA
 * exemption reason of a line: explicit line value, else the product's, else
 * the company default. In a country without VAT every line is out of scope.
 */
class VatClassifier
{
    /**
     * @return array{0: string, 1: ?string} [category, exemption code]
     */
    public function classify(?Product $product, float $rate, ?string $category = null, ?string $code = null, ?TaxProfile $profile = null): array
    {
        $profile ??= TaxProfile::current();
        // No tax in the branch's country (or the branch is not registered): every line is outside its scope.
        if (! $profile->chargesTax()) {
            return ['O', null];
        }
        $category = strtoupper((string) ($category ?: ($product && $product->vat_category !== 'S' ? $product->vat_category : ($rate > 0 ? 'S' : $this->company()?->setting('zatca_default_zero_category', 'Z')))));
        if ($category === 'S' && $rate <= 0) {
            $category = 'Z';
        }
        if (! array_key_exists($category, config('zatca.vat_categories'))) {
            throw ValidationException::withMessages(['items' => "فئة ضريبية غير معروفة: {$category}"]);
        }
        if ($category === 'S') {
            return ['S', null];
        }
        // Exemption reason codes (VATEX-SA-…) are ZATCA's; other countries only need the category.
        if (! $profile->isSaudi()) {
            return [$category, null];
        }

        $code = $code ?: ($product?->vat_category === $category ? $product?->vat_exemption_code : null) ?: $this->defaultCode($category);
        if ($code && ! isset(config('zatca.exemption_reasons')[$category][$code])) {
            throw ValidationException::withMessages(['items' => "سبب الإعفاء {$code} لا يناسب الفئة {$category}."]);
        }

        return [$category, $code];
    }

    /** The standard rate (%) of the current branch's country (KSA: 15). */
    public static function standardRate(?TaxProfile $profile = null): float
    {
        return ($profile ?? TaxProfile::current())->standardRate();
    }

    public function defaultCode(string $category): ?string
    {
        return $this->company()?->setting('zatca_default_exemption_'.$category) ?: null;
    }

    /** Reason text sent with the code (company wording for out-of-scope supplies). */
    public function reasonText(string $category, ?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if ($category === 'O' && ($custom = $this->company()?->setting('zatca_out_of_scope_reason'))) {
            return $custom;
        }

        return config("zatca.exemption_reasons.{$category}.{$code}.en");
    }

    protected function company(): ?Company
    {
        return app(TenantManager::class)->getCompany();
    }
}

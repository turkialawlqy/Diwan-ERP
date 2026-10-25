<?php

namespace App\Services\Tax;

use App\Models\Branch;
use App\Models\Company;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The tax and currency rules a document follows: those of the country its
 * branch operates in (config/countries.php), with the company's own rate
 * overrides. One profile per branch; a company without branches uses its
 * own country.
 */
class TaxProfile
{
    public readonly string $countryCode;

    public readonly array $country;

    public function __construct(public readonly Company $company, public readonly ?Branch $branch = null)
    {
        $code = strtoupper((string) ($branch?->country_code ?: $company->country_code ?: 'SA'));
        $this->countryCode = isset(config('countries')[$code]) ? $code : 'SA';
        $this->country = config('countries')[$this->countryCode];
    }

    public static function forBranch(Branch|int|null $branch, ?Company $company = null): self
    {
        $company ??= app(TenantManager::class)->getCompany();
        if (is_int($branch)) {
            $branch = Branch::withoutGlobalScopes()->where('company_id', $company?->id)->find($branch);
        }
        $company ??= $branch ? Company::find($branch->company_id) : null;

        return new self($company ?? new Company(['country_code' => 'SA', 'currency' => 'SAR']), $branch);
    }

    /** The profile of a document: its branch, else the company's main branch. */
    public static function forDocument(Model $document): self
    {
        $company = Company::find($document->company_id) ?? app(TenantManager::class)->getCompany();
        $branchId = $document->branch_id ?? null;

        return self::forBranch($branchId ? (int) $branchId : self::mainBranch($company), $company);
    }

    /** The signed-in user's branch, else the main branch (forms and defaults). */
    public static function current(?int $branchId = null): self
    {
        $company = app(TenantManager::class)->getCompany();
        $branchId ??= auth()->user()?->defaultBranchId();

        return self::forBranch($branchId ? (int) $branchId : self::mainBranch($company), $company);
    }

    /** Shortcut: the standard rate of the signed-in user's branch (form and line defaults). */
    public static function rate(): float
    {
        return self::current()->standardRate();
    }

    /** "in:0,15"-style rule: zero or a rate of the current branch's country. */
    public static function rateRule(): string
    {
        return 'in:'.implode(',', array_unique([0, ...self::current()->rates()]));
    }

    protected static function mainBranch(?Company $company): ?Branch
    {
        if (! $company) {
            return null;
        }

        return Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderByDesc('is_main')->orderBy('id')->first();
    }

    // ------------------------------------------------------------ tax

    public function hasTax(): bool
    {
        return (bool) $this->country['tax']['has'];
    }

    /** Whether this branch charges tax: the country has one and the branch is registered. */
    public function chargesTax(): bool
    {
        return $this->hasTax() && ($this->branch?->tax_registered ?? true);
    }

    public function taxName(): string
    {
        return $this->country['tax']['name'];
    }

    /** The company's rates for the country in force on a date (overrides with an effective date), else the catalogue's. */
    protected function rateSet(Carbon|string|null $date = null): array
    {
        $day = Carbon::parse($date ?? now())->toDateString();
        $override = collect($this->company->id ? ($this->company->setting('tax_rates.'.$this->countryCode) ?? []) : [])
            ->filter(fn ($r) => ($r['from'] ?? '0000-00-00') <= $day)->sortBy('from')->last();

        return $override
            ? ['standard' => (float) $override['standard'], 'rates' => array_values(array_unique(array_map('floatval', [$override['standard'], ...($override['rates'] ?? [])])))]
            : ['standard' => (float) $this->country['tax']['standard'], 'rates' => array_map('floatval', $this->country['tax']['rates'])];
    }

    public function standardRate(Carbon|string|null $date = null): float
    {
        return $this->chargesTax() ? $this->rateSet($date)['standard'] : 0.0;
    }

    /** @return float[] the rates a taxable line may carry (standard first) */
    public function rates(Carbon|string|null $date = null): array
    {
        return $this->chargesTax() ? $this->rateSet($date)['rates'] : [];
    }

    /** The rate a taxable line gets: the one asked for when the country allows it, else the standard rate. */
    public function rateFor(?float $requested, Carbon|string|null $date = null): float
    {
        $rates = $this->rates($date);
        if (! $rates) {
            return 0.0;
        }

        return $requested !== null && in_array(round($requested, 2), array_map(fn ($r) => round($r, 2), $rates), true) ? (float) $requested : $rates[0];
    }

    /** Whether a VAT amount entered by hand is the net at one of the allowed rates. */
    public function vatMatches(float $net, float $vat): bool
    {
        foreach ($this->rates() as $rate) {
            if (abs($vat - round($net * $rate / 100, 2)) <= 0.011) {
                return true;
            }
        }

        return false;
    }

    /** "15%" or "14% أو 5%" for messages. */
    public function ratesLabel(): string
    {
        return $this->rates() ? implode(' أو ', array_map(fn ($r) => rtrim(rtrim(number_format($r, 2), '0'), '.').'%', $this->rates())) : '0%';
    }

    // ------------------------------------------------------------ registration

    public function taxNumberLabel(): string
    {
        return $this->country['tax_number']['label'];
    }

    public function validTaxNumber(?string $number): bool
    {
        return $number === null || $number === '' || (bool) preg_match($this->country['tax_number']['pattern'], $number);
    }

    public function taxNumberHint(): string
    {
        $example = $this->country['tax_number']['example'];

        return $this->taxNumberLabel().' غير صحيح لـ'.$this->country['name'].($example ? " (مثال: {$example})" : '').'.';
    }

    /** The seller's registration: the branch's own, else the company's when the branch is in the company's country. */
    public function sellerTaxNumber(): ?string
    {
        if ($this->branch?->tax_number) {
            return $this->branch->tax_number;
        }

        return $this->countryCode === strtoupper((string) ($this->company->country_code ?: 'SA')) ? $this->company->tax_number : null;
    }

    // ------------------------------------------------------------ invoicing

    public function isSaudi(): bool
    {
        return $this->countryCode === 'SA';
    }

    public function einvoicing(): ?string
    {
        return $this->country['einvoicing'];
    }

    public function requiresNationalAddress(): bool
    {
        return (bool) $this->country['national_address'];
    }

    /** Whether B2C sales get a simplified tax invoice (KSA style). */
    public function simplifiedInvoices(): bool
    {
        return (bool) $this->country['simplified_invoices'];
    }

    /** [Arabic, English] title of a sales invoice. */
    public function invoiceTitle(bool $simplified = false): array
    {
        if (! $this->chargesTax()) {
            return ['فاتورة', 'Invoice'];
        }

        return $simplified && $this->simplifiedInvoices() ? ['فاتورة ضريبية مبسطة', 'Simplified Tax Invoice'] : ['فاتورة ضريبية', 'Tax Invoice'];
    }

    // ------------------------------------------------------------ currency & time

    /** The branch's operating currency: its own, else the company's base when in the company's country, else the country's. */
    public function currency(): string
    {
        if ($this->branch?->currency) {
            return strtoupper($this->branch->currency);
        }

        return $this->countryCode === strtoupper((string) ($this->company->country_code ?: 'SA'))
            ? strtoupper((string) ($this->company->currency ?: $this->country['currency']))
            : $this->country['currency'];
    }

    public function timezone(): string
    {
        return $this->branch?->timezone ?: $this->country['timezone'];
    }

    public function today(): string
    {
        return now($this->timezone())->toDateString();
    }

    /** For the forms (shared as page.props.tax). */
    public function toArray(): array
    {
        return [
            'country' => $this->countryCode,
            'country_name' => $this->country['name'],
            'has_tax' => $this->chargesTax(),
            'name' => $this->taxName(),
            'short' => $this->country['tax']['short'],
            'rate' => $this->standardRate(),
            'rates' => $this->rates(),
            'tax_number_label' => $this->taxNumberLabel(),
            'currency' => $this->currency(),
            'einvoicing' => $this->einvoicing(),
            'branch_id' => $this->branch?->id,
        ];
    }

    /** Catalogue entry of a country (defaults to Saudi Arabia). */
    public static function country(?string $code): array
    {
        return config('countries')[strtoupper((string) $code)] ?? config('countries')['SA'];
    }

    /** The country of a free-text customer / supplier country ("مصر", "Egypt", "EG"). */
    public static function countryCodeFromName(?string $name, string $default = 'SA'): string
    {
        $value = mb_strtolower(trim((string) $name));
        if ($value === '') {
            return $default;
        }
        foreach (config('countries') as $code => $country) {
            if ($value === mb_strtolower($code) || $value === mb_strtolower($country['name']) || str_contains(mb_strtolower($country['name']), $value) && mb_strlen($value) > 3) {
                return $code;
            }
        }
        $aliases = ['ksa' => 'SA', 'saudi arabia' => 'SA', 'السعودية' => 'SA', 'uae' => 'AE', 'الإمارات' => 'AE', 'الامارات' => 'AE', 'egypt' => 'EG',
            'jordan' => 'JO', 'bahrain' => 'BH', 'oman' => 'OM', 'عمان' => 'OM', 'qatar' => 'QA', 'kuwait' => 'KW', 'yemen' => 'YE', 'algeria' => 'DZ',
            'morocco' => 'MA', 'tunisia' => 'TN', 'lebanon' => 'LB', 'iraq' => 'IQ', 'palestine' => 'PS', 'sudan' => 'SD', 'syria' => 'SY', 'libya' => 'LY'];

        return $aliases[$value] ?? '??';
    }
}

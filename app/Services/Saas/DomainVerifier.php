<?php

namespace App\Services\Saas;

use App\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom domains are only served after DNS proof of ownership:
 * a TXT record "_diwan-verify.{domain}" holding the company's token.
 */
class DomainVerifier
{
    public function assign(Company $company, ?string $domain): void
    {
        $domain = $domain ? strtolower(trim($domain)) : null;
        if ($domain) {
            $central = config('tenancy.central_domain');
            if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) || str_ends_with($domain, '.'.$central) || $domain === $central) {
                throw ValidationException::withMessages(['custom_domain' => 'اسم النطاق غير صالح.']);
            }
            if (Company::where('custom_domain', $domain)->whereKeyNot($company->id)->exists()) {
                throw ValidationException::withMessages(['custom_domain' => 'النطاق مستخدم من منشأة أخرى.']);
            }
        }

        $company->forceFill([
            'custom_domain' => $domain,
            'custom_domain_token' => $domain ? Str::random(40) : null,
            'custom_domain_verified_at' => null,
        ])->save();
    }

    public function verify(Company $company): bool
    {
        if (! $company->custom_domain || ! $company->custom_domain_token) {
            return false;
        }

        $records = $this->txtRecords('_diwan-verify.'.$company->custom_domain);
        if (! in_array($company->custom_domain_token, $records, true)) {
            return false;
        }

        $company->forceFill(['custom_domain_verified_at' => now()])->save();

        return true;
    }

    /** @return string[] */
    protected function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn ($r) => trim($r['txt'] ?? implode('', $r['entries'] ?? [])), $records);
    }
}

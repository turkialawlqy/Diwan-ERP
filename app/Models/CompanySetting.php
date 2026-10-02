<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CompanySetting extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'group',
        'key',
        'value',
        'type',
    ];

    protected static function booted(): void
    {
        $flush = fn (CompanySetting $setting) => Cache::forget(Company::settingsCacheKey((int) $setting->company_id));

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Never write secrets (ZATCA keys, OTPs, API tokens) into the audit log.
     */
    protected function auditFilter(array $attributes): array
    {
        $attributes = array_diff_key($attributes, array_flip(['created_at', 'updated_at']));
        $key = (string) ($this->getAttribute('key') ?? '');

        if (array_key_exists('value', $attributes) && preg_match('/secret|otp|csid|private|token|password|certificate|key$/i', $key)) {
            $attributes['value'] = '***';
        }

        return $attributes;
    }
}

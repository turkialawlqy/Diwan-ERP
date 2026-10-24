<?php

namespace App\Models;

use App\Traits\SerializesLocalDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory, SerializesLocalDates;

    protected $fillable = [
        'name',
        'subdomain',
        'custom_domain',
        'email',
        'phone',
        'tax_number',
        'commercial_registration',
        'logo',
        'status',
        'trial_ends_at',
        'subscription_plan_id',
        'currency',
        'country_code',
        'timezone',
        'language',
        'is_verified',
        'subscription_ends_at',
        'billing_cycle',
        'suspended_reason',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'custom_domain_verified_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    protected $hidden = ['custom_domain_token'];

    protected $appends = [
        'logo_url',
    ];

    /**
     * Get the full URL for company logo.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (empty($this->logo)) {
            return null;
        }

        if (str_starts_with($this->logo, 'http://') || str_starts_with($this->logo, 'https://')) {
            return $this->logo;
        }

        // Served through the app (company.logo) so printing and previews work
        // whether or not `php artisan storage:link` was run on the server. The
        // version suffix busts the browser cache when the logo is replaced.
        if ($this->logoPath()) {
            return '/company/logo?v='.substr(md5($this->logo), 0, 10);
        }

        return $this->logo;
    }

    /**
     * Path of the uploaded logo on the public disk, or null when the logo is
     * missing or external.
     */
    public function logoPath(): ?string
    {
        if (empty($this->logo) || preg_match('#^https?://#i', $this->logo)) {
            return null;
        }

        $path = ltrim(preg_replace('#^/?storage/#', '', $this->logo), '/');

        if (str_contains($path, '..') || ! str_starts_with($path, 'logos/'.$this->id.'/')) {
            return null;
        }

        return Storage::disk('public')->exists($path) ? $path : null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(CompanySetting::class);
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(Currency::class);
    }

    /**
     * Get a setting by key.
     */
    public function setting(string $key, $default = null)
    {
        $setting = $this->cachedSettings()[$key] ?? null;
        if (! $setting) {
            return $default;
        }

        $setting = (object) $setting;

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    /**
     * All settings of the company, cached (invalidated by CompanySetting events).
     */
    public function cachedSettings(): array
    {
        return Cache::remember(
            static::settingsCacheKey($this->id),
            now()->addHours(6),
            fn () => CompanySetting::withoutGlobalScopes()
                ->where('company_id', $this->id)
                ->get(['key', 'value', 'type'])
                ->keyBy('key')
                ->map(fn ($s) => ['value' => $s->value, 'type' => $s->type])
                ->all()
        );
    }

    public static function settingsCacheKey(int $companyId): string
    {
        return "company:{$companyId}:settings";
    }

    /**
     * Set a setting value.
     */
    public function setSetting(string $key, $value, string $group = 'general', string $type = 'string'): void
    {
        $stringValue = is_array($value) ? json_encode($value) : (string) $value;
        $this->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue, 'group' => $group, 'type' => $type]
        );
    }
}

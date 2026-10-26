<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use Auditable, BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name_ar',
        'name_en',
        'code',
        'phone',
        'address',
        'city',
        'is_main',
        'is_active',
        'country_code',
        'currency',
        'tax_number',
        'tax_registered',
        'commercial_registration',
        'timezone',
    ];

    protected $casts = ['is_main' => 'boolean', 'is_active' => 'boolean', 'tax_registered' => 'boolean'];

    protected $appends = ['name'];

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : ($this->name_ar ?? '');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

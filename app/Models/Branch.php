<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory, BelongsToCompany;

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
    ];

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

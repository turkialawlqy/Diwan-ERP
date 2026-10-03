<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'price_monthly',
        'price_yearly',
        'max_users',
        'max_branches',
        'max_invoices_per_month',
        'features',
        'is_active',
        'is_popular',
        'modules',
        'max_warehouses',
        'max_employees',
        'trial_days',
        'sort',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'max_users' => 'integer',
        'max_branches' => 'integer',
        'max_invoices_per_month' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'modules' => 'array',
        'max_warehouses' => 'integer',
        'max_employees' => 'integer',
    ];

    /** Null modules means the plan includes every module. */
    public function includesModule(string $module): bool
    {
        return $this->modules === null || in_array($module, $this->modules, true);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}

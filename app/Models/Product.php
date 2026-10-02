<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'category_id',
        'unit_id',
        'name_ar',
        'name_en',
        'sku',
        'barcode',
        'description',
        'purchase_price',
        'selling_price',
        'tax_rate',
        'min_stock_alert',
        'track_inventory',
        'type',
        'image',
        'is_active',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'min_stock_alert' => 'decimal:2',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = ['name', 'total_stock'];

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : ($this->name_ar ?? '');
    }

    public function getTotalStockAttribute(): float
    {
        return (float) $this->stocks()->sum('quantity');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(PosOrderItem::class);
    }
}

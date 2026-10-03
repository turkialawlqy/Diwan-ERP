<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, BelongsToCompany, HasFactory, SoftDeletes;

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
        'average_cost',
        'tracking',
        'reorder_level',
        'reorder_quantity',
        'is_kit',
        'selling_price',
        'tax_rate',
        'min_stock_alert',
        'track_inventory',
        'type',
        'image',
        'is_active', 'vat_category', 'vat_exemption_code',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'min_stock_alert' => 'decimal:4',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
    ];

    // total_stock is appended explicitly by listings that need it (see withStockTotal).
    protected $appends = ['name'];

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : ($this->name_ar ?? '');
    }

    /**
     * Total stock across warehouses. Uses withSum('stocks', 'quantity') or the
     * loaded relation when available so listing products is not N+1.
     */
    public function getTotalStockAttribute(): float
    {
        if (array_key_exists('stocks_sum_quantity', $this->attributes)) {
            return (float) $this->attributes['stocks_sum_quantity'];
        }

        if ($this->relationLoaded('stocks')) {
            return (float) $this->stocks->sum('quantity');
        }

        return (float) $this->stocks()->sum('quantity');
    }

    /**
     * Eager-load the stock total for listings.
     */
    public function scopeWithStockTotal($query)
    {
        return $query->withSum('stocks', 'quantity');
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

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function kitItems(): HasMany
    {
        return $this->hasMany(ProductKitItem::class, 'kit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * A stock item (as opposed to a service / non-tracked item).
     */
    public function isStockItem(): bool
    {
        return (bool) $this->track_inventory && ($this->type ?? 'product') !== 'service';
    }
}

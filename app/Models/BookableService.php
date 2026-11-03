<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A service a customer can book: duration, price, deposit and which resources can serve it. */
class BookableService extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'name', 'description', 'duration_minutes', 'buffer_minutes', 'price', 'tax_rate', 'product_id', 'deposit_percent',
        'online_bookable', 'free_cancel_hours', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'tax_rate' => 'decimal:2', 'online_bookable' => 'boolean', 'is_active' => 'boolean',
        'duration_minutes' => 'integer', 'buffer_minutes' => 'integer', 'deposit_percent' => 'integer', 'free_cancel_hours' => 'integer'];

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(BookingResource::class, 'bookable_service_resource');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

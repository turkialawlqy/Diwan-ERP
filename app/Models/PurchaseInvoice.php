<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, HasFactory;

    protected $fillable = [
        'currency_code',
        'exchange_rate',
        'source',
        'bank_account_id',
        'company_id',
        'supplier_id',
        'purchase_order_id',
        'warehouse_id',
        'branch_id',
        'invoice_number',
        'supplier_invoice_number',
        'invoice_date',
        'due_date',
        'supply_date',
        'payment_method',
        'payment_status',
        'subtotal',
        'discount',
        'tax_rate',
        'tax_amount',
        'total',
        'paid_amount',
        'remaining_amount',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'supply_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function landedCosts(): HasMany
    {
        return $this->hasMany(LandedCost::class);
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCost extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_invoice_id', 'landed_number', 'date', 'description', 'amount', 'tax_amount', 'paid_from', 'supplier_id', 'bank_account_id', 'allocation', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'allocation' => 'array'];

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

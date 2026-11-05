<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, HasFactory;

    protected $fillable = [
        'branch_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'currency_code',
        'exchange_rate',
        'bank_account_id',
        'wht_rate',
        'wht_amount',
        'wht_category',
        'company_id',
        'supplier_id',
        'purchase_invoice_id',
        'payment_number',
        'amount',
        'payment_date',
        'payment_method',
        'reference_number',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'wht_rate' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** A document that hangs on another (a payment on its invoice...) belongs to that document's branch. */
    public function inheritedBranchId(): ?int
    {
        return PurchaseInvoice::withoutGlobalScope('branch')->whereKey($this->purchase_invoice_id)->value('branch_id');
    }
}

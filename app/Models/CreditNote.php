<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected $fillable = [
        'currency_code',
        'exchange_rate',
        'warehouse_id',
        'kind',
        'zatca_uuid',
        'bank_account_id', 'refund_method',
        'company_id',
        'invoice_id',
        'customer_id',
        'credit_note_number',
        'credit_note_date',
        'subtotal',
        'tax_amount',
        'total',
        'reason',
        'status',
        'zatca_qr',
    ];

    protected $casts = [
        'credit_note_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }
}

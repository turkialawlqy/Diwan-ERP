<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebitNote extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'customer_id', 'invoice_id', 'debit_note_number', 'debit_note_date', 'reason', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'status', 'zatca_uuid', 'zatca_qr', 'created_by', 'currency_code', 'exchange_rate'];

    protected $casts = ['debit_note_date' => 'date', 'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

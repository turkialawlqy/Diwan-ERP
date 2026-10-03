<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'credit_note_id', 'invoice_item_id', 'product_id', 'item_name', 'quantity', 'unit_price', 'tax_rate', 'tax_amount', 'total', 'return_to_stock', 'vat_category', 'vat_exemption_code'];

    protected $casts = ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2', 'return_to_stock' => 'boolean'];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->creditNote;
    }
}

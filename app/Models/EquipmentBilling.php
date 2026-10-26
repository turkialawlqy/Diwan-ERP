<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentBilling extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'direction', 'equipment_id', 'rental_id', 'period_from', 'period_to', 'quantity', 'unit', 'rate', 'amount',
        'tax_amount', 'invoice_id', 'project_cost_id', 'journal_entry_id', 'reference', 'created_by'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'quantity' => 'float', 'rate' => 'float', 'amount' => 'float', 'tax_amount' => 'float'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(EquipmentRental::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringInvoiceRun extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'recurring_invoice_id', 'period_date', 'invoice_id'];

    protected $casts = ['period_date' => 'date'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

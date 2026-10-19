<?php

namespace App\Models;

use App\Traits\SerializesLocalDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    use SerializesLocalDates;

    protected $fillable = ['company_id', 'subscription_plan_id', 'number', 'billing_cycle', 'period_start', 'period_end', 'amount', 'vat_amount', 'total', 'status', 'payment_method', 'payment_reference', 'paid_at', 'confirmed_by', 'qr', 'issued_at'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'paid_at' => 'datetime', 'issued_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}

<?php

namespace App\Models;

use App\Services\Documents\DocumentNumberService;
use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable, BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'price_list_id',
        'salesperson_id',
        'payment_terms_days',
        'company_id',
        'customer_type',
        'code',
        'name',
        'company_name',
        'contact_name',
        'email',
        'phone',
        'mobile',
        'tax_number',
        'commercial_registration',
        'address',
        'building_number',
        'street_name',
        'district',
        'city',
        'province',
        'postal_code',
        'additional_details',
        'country',
        'credit_limit',
        'opening_balance',
        'current_balance',
        'status',
        'notes',
        'additional_contacts',
        'attachments',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'additional_contacts' => 'array',
        'attachments' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($customer) {
            if (empty($customer->code) && ! empty($customer->company_id)) {
                $customer->code = app(DocumentNumberService::class)
                    ->next('customer', null, (int) $customer->company_id);
            }
        });
    }

    /**
     * The company's walk-in (cash) customer used for anonymous POS sales.
     */
    public static function walkIn(): self
    {
        return static::firstOrCreate(
            ['code' => 'WALK-IN'],
            ['name' => 'عميل نقدي', 'customer_type' => 'individual', 'status' => 'active'],
        );
    }

    public function getFormattedCodeAttribute(): string
    {
        return $this->code ?: ('1-'.str_pad($this->id, 6, '0', STR_PAD_LEFT));
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}

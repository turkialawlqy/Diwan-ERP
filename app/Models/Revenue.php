<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Revenue extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'account_id',
        'bank_account_id',
        'revenue_number',
        'title',
        'category',
        'amount',
        'tax_amount',
        'total_amount',
        'revenue_date',
        'payment_method',
        'reference_number',
        'customer_name',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'revenue_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

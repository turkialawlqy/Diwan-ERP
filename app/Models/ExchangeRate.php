<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'currency_code', 'date', 'rate', 'created_by'];

    protected $casts = ['date' => 'date', 'rate' => 'decimal:8'];
}

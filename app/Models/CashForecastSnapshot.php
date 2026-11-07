<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashForecastSnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'scope', 'taken_on', 'opening', 'weeks', 'scenario', 'taken_by'];

    protected $casts = ['taken_on' => 'date', 'opening' => 'decimal:2', 'weeks' => 'array', 'scenario' => 'array'];
}

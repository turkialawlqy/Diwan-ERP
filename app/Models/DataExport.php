<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExport extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'status', 'path', 'size', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

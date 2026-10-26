<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentDispatch extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'documentable_type', 'documentable_id', 'channel', 'recipient', 'cc', 'message', 'status', 'error', 'user_id', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

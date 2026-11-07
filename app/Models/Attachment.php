<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'attachable_type', 'attachable_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'url', 'events', 'secret', 'is_active', 'last_delivery_at', 'last_status', 'failures'];

    protected $casts = ['events' => 'array', 'secret' => 'encrypted', 'is_active' => 'boolean', 'last_delivery_at' => 'datetime'];

    protected $hidden = ['secret'];
}

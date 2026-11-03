<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time sign-in code (stored only as a keyed hash). */
class PortalLoginCode extends Model
{
    public $timestamps = false;

    protected $fillable = ['portal_user_id', 'code_hash', 'expires_at', 'attempts', 'used_at', 'ip', 'created_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
}

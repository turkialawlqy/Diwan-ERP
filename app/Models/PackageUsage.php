<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Ledger line of a customer package: -1 a visit used, +1 given back. */
class PackageUsage extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'customer_package_id', 'booking_id', 'delta', 'note'];
}

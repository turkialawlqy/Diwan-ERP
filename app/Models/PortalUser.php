<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer's login to the portal. Not a staff User: it has its own guard and can only
 * ever see the records of its own customer. Signs in with a one-time code sent by e-mail.
 */
class PortalUser extends Authenticatable
{
    use BelongsToCompany, Notifiable;

    protected $fillable = ['company_id', 'customer_id', 'name', 'email', 'status', 'invited_by', 'invited_at', 'last_login_at'];

    protected $casts = ['invited_at' => 'datetime', 'last_login_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PortalLoginCode::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The portal address this customer signs in at. */
    public function loginUrl(): string
    {
        return route('customer-portal.login', ['company' => $this->company_id]);
    }
}

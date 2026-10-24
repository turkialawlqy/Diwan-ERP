<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An e-invoice generation solution unit (EGS) registered with ZATCA.
 * Keys and CSIDs are encrypted at rest and never serialised.
 */
class ZatcaDevice extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'name', 'serial_number', 'environment', 'invoice_types', 'status',
        'private_key', 'csr', 'compliance_token', 'compliance_secret', 'compliance_request_id',
        'production_token', 'production_secret', 'certificate_expires_at', 'icv', 'last_hash',
        'compliance_checks', 'onboarded_at',
    ];

    protected $hidden = ['private_key', 'compliance_token', 'compliance_secret', 'production_token', 'production_secret', 'csr'];

    protected $casts = [
        'private_key' => 'encrypted',
        'compliance_token' => 'encrypted',
        'compliance_secret' => 'encrypted',
        'production_token' => 'encrypted',
        'production_secret' => 'encrypted',
        'compliance_checks' => 'array',
        'certificate_expires_at' => 'datetime',
        'onboarded_at' => 'datetime',
        'icv' => 'integer',
    ];

    /** Hash of "0" — the PIH of the very first invoice of a device. */
    public const INITIAL_HASH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ZatcaDocument::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->production_token;
    }

    /**
     * Credentials used for signing / API calls: the production CSID once the
     * device has one (a stopped device still submits the documents it signed),
     * the compliance CSID during onboarding.
     */
    public function credentials(): array
    {
        return $this->production_token
            ? [$this->production_token, $this->production_secret]
            : [$this->compliance_token, $this->compliance_secret];
    }

    /** Base64 DER certificate (the binary security token is base64 of it). */
    public function certificate(): ?string
    {
        [$token] = $this->credentials();

        return $token ? base64_decode($token) : null;
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The customer's acceptance of one version of a contract (with the evidence: who, when, from where). */
class ContractAcceptance extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'contract_id', 'portal_user_id', 'name', 'version', 'body_hash', 'accepted_at', 'ip', 'user_agent'];

    protected $casts = ['accepted_at' => 'datetime'];

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }
}

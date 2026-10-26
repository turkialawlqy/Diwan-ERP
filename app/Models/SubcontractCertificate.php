<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubcontractCertificate extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'subcontract_id', 'number', 'date', 'cumulative_value', 'current_value', 'advance_recovery', 'retention', 'vat_rate', 'vat_amount', 'net_payable', 'supplier_invoice_number', 'status', 'created_by'];

    protected $casts = ['date' => 'date'];

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class);
    }

    public function tenantParent(): ?Model
    {
        return $this->subcontract;
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ZatcaDocument extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'zatca_device_id', 'documentable_type', 'documentable_id', 'document_number', 'type_code', 'subtype',
        'uuid', 'icv', 'previous_hash', 'invoice_hash', 'signed_xml', 'cleared_xml', 'qr', 'submission', 'status',
        'warnings', 'errors', 'attempts', 'submitted_at',
    ];

    protected $hidden = ['signed_xml', 'cleared_xml'];

    protected $casts = ['warnings' => 'array', 'errors' => 'array', 'submitted_at' => 'datetime', 'icv' => 'integer'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZatcaDevice::class, 'zatca_device_id');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The XML to hand to the buyer: ZATCA's cleared copy when there is one. */
    public function finalXml(): string
    {
        return $this->cleared_xml ?: $this->signed_xml;
    }
}

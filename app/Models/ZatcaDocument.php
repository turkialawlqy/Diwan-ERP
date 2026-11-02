<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ZatcaDocument extends Model
{
    use BelongsToCompany;

    /** Seconds a failed document waits before it is sent again, after its 1st, 2nd... attempt (the last from then on). */
    public const RETRY_DELAYS = [60, 300, 900, 1800, 3600];

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

    /** The wait before sending again a document that failed after this many attempts. */
    public static function retryDelay(int $attempts): int
    {
        return self::RETRY_DELAYS[min(max($attempts, 1), count(self::RETRY_DELAYS)) - 1];
    }

    /**
     * Documents that may be sent now: pending ones, and failed ones whose
     * back-off since the last attempt (updated_at) is over. $grace adds seconds.
     */
    public function scopeDue(Builder $query, int $grace = 0): void
    {
        $query->where(function (Builder $query) use ($grace) {
            $query->where(fn ($q) => $q->where('status', 'pending')->where('updated_at', '<=', now()->subSeconds($grace)));
            // The delays only grow, so "at most N attempts and waited the Nth delay" for each N covers every count.
            foreach (self::RETRY_DELAYS as $i => $delay) {
                $query->orWhere(fn ($q) => $q->where('status', 'failed')->where('updated_at', '<=', now()->subSeconds($delay + $grace))
                    ->when($i < count(self::RETRY_DELAYS) - 1, fn ($q) => $q->where('attempts', '<=', $i + 1)));
            }
        });
    }
}

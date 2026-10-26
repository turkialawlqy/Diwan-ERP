<?php

namespace App\Models;

use App\Services\TenantManager;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write an audit entry without going through Eloquent events (so auditing
     * can never recurse) and without failing the business operation.
     */
    public static function record(string $event, ?Model $subject = null, array $old = [], array $new = [], ?int $companyId = null, ?int $userId = null): void
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            static::query()->getQuery()->insert([
                'company_id' => $companyId ?? $subject?->getAttribute('company_id') ?? app(TenantManager::class)->getTenantId(),
                'user_id' => $userId ?? auth()->id(),
                'event' => $event,
                'auditable_type' => $subject ? $subject->getMorphClass() : null,
                'auditable_id' => $subject?->getKey(),
                'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                'new_values' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'url' => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

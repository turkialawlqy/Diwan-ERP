<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Records create / update / delete of the model into audit_logs with the
 * before/after values of the changed attributes.
 */
trait Auditable
{
    protected static array $auditExclude = ['created_at', 'updated_at', 'password', 'remember_token'];

    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            AuditLog::record('created', $model, [], $model->auditFilter($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditFilter($model->getChanges());
            if (! $changes) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);
            AuditLog::record('updated', $model, $old, $changes);
        });

        static::deleted(function ($model) {
            AuditLog::record('deleted', $model, $model->auditFilter($model->getAttributes()), []);
        });
    }

    protected function auditFilter(array $attributes): array
    {
        $exclude = array_merge(static::$auditExclude, $this->getHidden());

        return array_diff_key($attributes, array_flip($exclude));
    }
}

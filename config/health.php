<?php

return [
    // /health fails when the newest successful backup is older than this.
    'backup_max_hours' => (int) env('HEALTH_BACKUP_MAX_HOURS', 26),

    // ... when the scheduler heartbeat is older than this (scheduler stopped).
    'scheduler_max_minutes' => (int) env('HEALTH_SCHEDULER_MAX_MINUTES', 5),

    // ... when a queued job has waited longer than this (no worker running).
    'queue_max_minutes' => (int) env('HEALTH_QUEUE_MAX_MINUTES', 15),

    // Backup and scheduler checks only matter where they are expected to run.
    'check_operations' => in_array(env('HEALTH_CHECK_OPERATIONS'), [null, ''], true) ? env('APP_ENV') === 'production' : (bool) env('HEALTH_CHECK_OPERATIONS'),
];

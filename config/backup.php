<?php

return [
    // Filesystem disk receiving backups (see config/filesystems.php).
    'disk' => env('BACKUP_DISK', 'backups'),

    // Archives are AES-256 encrypted with this password. Keep it outside the
    // server (password manager): without it a backup cannot be restored.
    'password' => env('BACKUP_PASSWORD'),

    // Kept: the last N daily, N weekly (Sunday) and N monthly (1st) backups.
    'keep' => [
        'daily' => (int) env('BACKUP_KEEP_DAILY', 7),
        'weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4),
        'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 6),
    ],

    // Optional scratch MySQL database the verify command restores into. When
    // empty, verification checks the dump's tables and row counts instead.
    'verify_database' => env('BACKUP_VERIFY_DATABASE'),

    // Address alerted when a backup or verification fails.
    'notify' => env('BACKUP_NOTIFY_EMAIL'),

    // Second disk every backup is copied to (off the server). Empty = none.
    'offsite_disk' => env('BACKUP_OFFSITE_DISK'),

    // Pre-update backups (erp:backup --tag=preupdate) kept apart from rotation.
    'keep_tagged' => (int) env('BACKUP_KEEP_TAGGED', 5),

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
];

<?php

/*
| Offline branches: what THIS server is in the synchronisation (the table classification is in config/sync.php).
|  role   off (the default: nothing syncs) | hub (the head office: accepts branches) | branch (sends to and receives from the hub)
|  hub_url / token  on a branch: where the head office is and the token it gave this branch (php artisan sync:node on the hub)
*/
return [
    'role' => env('SYNC_ROLE', 'off'),
    'hub_url' => env('SYNC_HUB_URL'),
    'token' => env('SYNC_TOKEN'),
    'batch' => (int) env('SYNC_BATCH', 200),
    // Seconds a change waits in the feed before it is sent, so changes committed out of order are never skipped by a cursor.
    'settle' => (int) env('SYNC_SETTLE', 5),
];

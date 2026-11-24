<?php

namespace App\Http\Middleware;

use App\Services\Sync\SyncHub;
use Closure;
use Illuminate\Http\Request;

/** A branch server's call to the head office: known by its bearer token only, and only when this server is the hub. */
class AuthenticateSyncNode
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('sync-node.role') === 'hub', 404);
        $node = (new SyncHub(config('database.default')))->authenticate($request->bearerToken());
        abort_unless($node, 401, 'Unknown node.');
        $request->attributes->set('sync_node', $node);

        return $next($request);
    }
}

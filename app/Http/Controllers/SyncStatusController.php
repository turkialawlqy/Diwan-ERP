<?php

namespace App\Http\Controllers;

use App\Services\Sync\SyncEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** «الإعدادات ← المزامنة»: how far this server's sync has got, and the changes that wait for a person. Owner only; 404 on a server that is not part of a sync. */
class SyncStatusController extends Controller
{
    public function index(Request $request)
    {
        $role = config('sync-node.role');
        abort_unless(in_array($role, ['branch', 'hub'], true) && $request->user()->isCompanyOwner(), 404);

        $engine = new SyncEngine(config('database.default'), $role);
        $cursor = DB::table('sync_cursors')->where('peer', 'hub')->first();

        return Inertia::render('Settings/Sync', [
            'role' => $role,
            'node' => $engine->nodeId(),
            'queue' => $role === 'branch'
                ? count($engine->pending((int) ($cursor->pushed ?? 0), 5000))
                : 0,
            'cursor' => $cursor ? ['pushed' => (int) $cursor->pushed, 'pulled' => (int) $cursor->pulled] : null,
            'nodes' => $role === 'hub'
                ? DB::table('sync_nodes')->orderBy('node_id')->get(['node_id', 'name', 'last_seen_at', 'acknowledged'])
                : [],
            'conflicts' => DB::table('sync_conflicts')->whereNull('resolved_at')->orderByDesc('id')->limit(100)->get(['id', 'node_id', 'table_name', 'pk', 'op', 'reason', 'created_at']),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncHub;
use Illuminate\Http\Request;

/** The head office's end of the branch sync (see SyncHub). Reached with a node token, not a user login. */
class SyncController extends Controller
{
    protected function hub(): SyncHub
    {
        return new SyncHub(config('database.default'));
    }

    public function handshake(Request $request)
    {
        return response()->json($this->hub()->handshake($request->attributes->get('sync_node'), $request->validate(['version' => 'required|string|max:30'])));
    }

    public function push(Request $request)
    {
        $data = $request->validate([
            'changes' => 'required|array|max:1000',
            'changes.*.node_id' => 'required|integer',
            'changes.*.seq' => 'required|integer',
            'changes.*.table' => 'required|string|max:100',
            'changes.*.pk' => 'required|integer',
            'changes.*.op' => 'required|in:I,U,D',
            'changes.*.payload' => 'nullable|array',
        ]);

        return response()->json($this->hub()->receive($request->attributes->get('sync_node'), $data['changes']));
    }

    public function pull(Request $request)
    {
        $data = $request->validate(['after' => 'required|integer|min:0', 'limit' => 'nullable|integer|min:1|max:500']);

        return response()->json($this->hub()->feed($request->attributes->get('sync_node'), (int) $data['after'], (int) ($data['limit'] ?? 200)));
    }
}

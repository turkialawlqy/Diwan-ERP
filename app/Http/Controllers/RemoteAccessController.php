<?php

namespace App\Http\Controllers;

use App\Services\RemoteAccess;
use App\Support\LocalNetwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «الإعدادات ← الوصول من الإنترنت»: Cloudflare Tunnel or Tailscale for a Linux local install. Owner only, because it
 * changes how the whole system is exposed. The tunnel token / Tailscale key is passed to the root helper and never stored.
 */
class RemoteAccessController extends Controller
{
    protected function authorizeOwner(Request $request, RemoteAccess $remote): void
    {
        abort_unless($remote->enabled() && $request->user()->isCompanyOwner(), 404);
    }

    public function edit(Request $request, RemoteAccess $remote)
    {
        $this->authorizeOwner($request, $remote);

        return Inertia::render('Settings/RemoteAccess', [
            'status' => $remote->status(),
            'active' => $remote->active(),
            'lan' => LocalNetwork::urls($request),
        ]);
    }

    public function status(Request $request, RemoteAccess $remote): JsonResponse
    {
        $this->authorizeOwner($request, $remote);

        return response()->json(['status' => $remote->status(), 'active' => $remote->active()]);
    }

    public function apply(Request $request, RemoteAccess $remote)
    {
        $this->authorizeOwner($request, $remote);
        if ($remote->running()) {
            return back()->withErrors(['mode' => 'هناك تطبيق جارٍ بالفعل، انتظر حتى ينتهي.']);
        }
        $remote->request($remote->validate($request->only('mode', 'domain', 'secret')));

        return back()->with('success', 'أُرسل الطلب إلى الخادم؛ يستغرق التطبيق دقيقة أو دقيقتين.');
    }
}

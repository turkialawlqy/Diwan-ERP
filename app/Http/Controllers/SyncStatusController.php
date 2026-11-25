<?php

namespace App\Http\Controllers;

use App\Services\Sync\HttpSyncTransport;
use App\Services\Sync\SyncBootstrap;
use App\Services\Sync\SyncEngine;
use App\Services\Sync\SyncHub;
use App\Services\Sync\SyncSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * «الإعدادات ← المزامنة»: links this server to a head office or branches from the page itself (no terminal, no file editing), and shows
 * how far the sync has got and the changes that wait for a person. Owner only; 404 on a server that is neither a local install nor
 * already part of a sync.
 */
class SyncStatusController extends Controller
{
    protected function authorizeOwner(Request $request): string
    {
        $role = (string) config('sync-node.role');
        abort_unless($request->user()->isCompanyOwner() && (config('app.local_install') || in_array($role, ['hub', 'branch'], true)), 404);

        return $role;
    }

    public function index(Request $request)
    {
        $role = $this->authorizeOwner($request);
        $linked = in_array($role, ['hub', 'branch'], true) && Schema::hasTable('sync_state');
        $engine = $linked ? new SyncEngine(config('database.default'), $role) : null;
        $cursor = $linked ? DB::table('sync_cursors')->where('peer', 'hub')->first() : null;

        return Inertia::render('Settings/Sync', [
            'role' => $linked ? $role : 'off',
            'node' => $engine?->nodeId(),
            'queue' => $role === 'branch' && $engine ? count($engine->pending((int) ($cursor->pushed ?? 0), 5000)) : 0,
            'cursor' => $cursor ? ['pushed' => (int) $cursor->pushed, 'pulled' => (int) $cursor->pulled] : null,
            'hubUrl' => $role === 'branch' ? config('sync-node.hub_url') : rtrim($request->getSchemeAndHttpHost(), '/'),
            'nodes' => $role === 'hub' ? DB::table('sync_nodes')->orderBy('node_id')->get(['node_id', 'name', 'last_seen_at', 'acknowledged']) : [],
            'code' => session('sync_code'),
            'conflicts' => $linked ? DB::table('sync_conflicts')->whereNull('resolved_at')->orderByDesc('id')->limit(100)->get(['id', 'node_id', 'table_name', 'pk', 'op', 'reason', 'created_at']) : [],
        ]);
    }

    /** Makes this server the head office. */
    public function enableHub(Request $request)
    {
        abort_unless($this->authorizeOwner($request) === 'off', 404);
        set_time_limit(0);
        SyncSettings::save(['role' => 'hub', 'node' => 1]);
        Artisan::call('sync:join', ['node' => 1]);

        return back()->with('success', 'هذا الخادم هو المكتب الرئيسي الآن. أضف فروعك من هنا.');
    }

    /** Head office: a new branch gets a number and a token, shown once as one connection code. */
    public function addBranch(Request $request)
    {
        abort_unless($this->authorizeOwner($request) === 'hub', 404);
        $data = $request->validate(['name' => 'required|string|max:100', 'hub_url' => ['required', 'string', 'max:200', 'regex:#^https?://[^\s]+$#']]);

        $node = max(2, (int) DB::table('sync_nodes')->max('node_id') + 1);
        abort_if($node > 99, 422, 'بلغت الحد الأقصى من الفروع (98).');
        $token = (new SyncHub(config('database.default')))->register($node, $data['name'], $request->user()->company_id);

        return back()->with('sync_code', SyncSettings::connectionCode(rtrim($data['hub_url'], '/'), $node, $token))
            ->with('success', "أُضيف الفرع «{$data['name']}» برقم {$node}. انسخ رمز الاتصال الآن: لا يظهر مرة أخرى.");
    }

    /** Head office: a branch is cut off (its token stops working); its data stays. */
    public function removeBranch(Request $request, int $node)
    {
        abort_unless($this->authorizeOwner($request) === 'hub', 404);
        DB::table('sync_nodes')->where('node_id', $node)->delete();

        return back()->with('success', 'فُصل الفرع: لم يعد رمزه مقبولًا.');
    }

    /** Branch: connects with the code, replaces this server's business data with the head office's, and starts following it. */
    public function connect(Request $request)
    {
        abort_unless($this->authorizeOwner($request) === 'off', 404);
        $data = $request->validate(['code' => 'required|string|max:2000', 'confirm' => 'accepted']);
        $code = SyncSettings::parseCode($data['code']);
        if (! $code) {
            return back()->withErrors(['code' => 'رمز الاتصال غير صالح. انسخه كاملًا من المكتب الرئيسي.']);
        }
        $transport = new HttpSyncTransport($code['hub_url'], $code['token']);
        try {
            $version = trim((string) @file_get_contents(base_path('VERSION')));
            $hello = $transport->post('handshake', ['version' => $version]);
        } catch (\Throwable $e) {
            return back()->withErrors(['code' => 'تعذر الاتصال بالمكتب الرئيسي أو الرمز غير مقبول: '.$e->getMessage()]);
        }
        if (! ($hello['ok'] ?? false)) {
            return back()->withErrors(['code' => 'المكتب الرئيسي بإصدار '.($hello['version'] ?? '?')." وهذا الخادم بإصدار {$version}: حدّث الأقدم منهما ثم أعد المحاولة."]);
        }

        set_time_limit(0);
        SyncSettings::save(['role' => 'branch', 'node' => $code['node'], 'hub_url' => $code['hub_url'], 'token' => $code['token']]);
        Artisan::call('sync:join', ['node' => $code['node'], '--force' => true]);
        try {
            $result = (new SyncBootstrap(config('database.default'), $transport))->run();
        } catch (\Throwable $e) {
            return back()->withErrors(['code' => 'توقف النسخ الأول: '.$e->getMessage().' (لا تتم المزامنة قبل إعادة المحاولة).']);
        }
        auth()->logout();   // the users of this server were replaced by the head office's

        return redirect()->route('login')->with('success', "تم الربط: نُسخت {$result['rows']} صفًّا من المكتب الرئيسي. سجّل الدخول ببيانات المكتب الرئيسي.");
    }

    /** Unlinks this server (head office or branch): the capture stops, the data stays. */
    public function disconnect(Request $request)
    {
        $role = $this->authorizeOwner($request);
        abort_unless(in_array($role, ['hub', 'branch'], true), 404);
        Artisan::call('sync:join', ['node' => 1, '--remove' => true]);
        SyncSettings::save(['role' => 'off', 'node' => 0]);

        return back()->with('success', 'فُصل هذا الخادم عن المزامنة. بياناته باقية كما هي.');
    }

    /** The owner's decision on one held change (see SyncEngine::resolveConflict). */
    public function resolve(Request $request, int $id, string $action)
    {
        $role = $this->authorizeOwner($request);
        abort_unless(in_array($role, ['branch', 'hub'], true) && in_array($action, ['accept', 'reject'], true), 404);

        $done = (new SyncEngine(config('database.default'), $role))->resolveConflict($id, $action);

        return back()->with($done ? 'success' : 'error', $done
            ? ($action === 'accept' ? 'طُبّق التغيير المحجوز.' : 'أُغلق التغيير المحجوز دون تطبيق.')
            : 'تعذر معالجة هذا التغيير (عولج من قبل أو لا يمكن تطبيقه).');
    }
}

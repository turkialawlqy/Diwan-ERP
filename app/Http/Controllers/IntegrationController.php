<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use App\Services\Saas\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * API tokens and outgoing webhooks of the company.
 */
class IntegrationController extends Controller
{
    /** Permissions an API token may carry. */
    public const API_ABILITIES = [
        'sales.clients.view', 'sales.clients.create', 'inventory.products.view', 'sales.invoices.view', 'sales.invoices.create',
    ];

    public function index(Request $request)
    {
        return Inertia::render('Settings/Integrations', [
            'tokens' => $request->user()->tokens()->latest()->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']),
            'abilities' => collect(self::API_ABILITIES)->filter(fn ($a) => $request->user()->can($a))->values(),
            'webhooks' => WebhookEndpoint::latest()->get(),
            'events' => config('saas.webhook_events'),
            'newToken' => session('new_token'),
            'newSecret' => session('new_secret'),
            'apiBase' => url('/api/v1'),
        ]);
    }

    public function storeToken(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'abilities' => 'required|array|min:1',
            'abilities.*' => ['string', Rule::in(self::API_ABILITIES)],
            'expires_in_days' => 'nullable|integer|min:1|max:730',
        ]);

        // A token can never carry a permission its owner lacks.
        $abilities = array_values(array_filter($validated['abilities'], fn ($a) => $request->user()->can($a)));
        $token = $request->user()->createToken($validated['name'], $abilities, isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null);

        return back()->with('new_token', $token->plainTextToken)->with('success', 'تم إنشاء الرمز. انسخه الآن، لن يظهر مرة أخرى.');
    }

    public function destroyToken(Request $request, int $token)
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('success', 'تم إلغاء الرمز');
    }

    public function storeWebhook(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url|max:500',
            'events' => 'required|array|min:1',
            'events.*' => ['string', Rule::in(array_merge(array_keys(config('saas.webhook_events')), ['*']))],
        ]);
        WebhookDispatcher::assertSafeUrl($validated['url']);

        $secret = 'whsec_'.Str::random(40);
        WebhookEndpoint::create($validated + ['secret' => $secret, 'is_active' => true]);

        return back()->with('new_secret', $secret)->with('success', 'تم تسجيل الويب هوك. احفظ مفتاح التوقيع.');
    }

    public function destroyWebhook(WebhookEndpoint $webhook)
    {
        $webhook->delete();

        return back()->with('success', 'تم حذف الويب هوك');
    }

    public function testWebhook(WebhookEndpoint $webhook)
    {
        DeliverWebhook::dispatch($webhook->id, 'ping', ['message' => config('app.brand.name_en').' webhook test'], (string) Str::uuid());

        return back()->with('success', 'تم إرسال حدث تجريبي');
    }
}

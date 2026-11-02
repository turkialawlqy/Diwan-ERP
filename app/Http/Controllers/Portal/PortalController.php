<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Portal\PortalScope;
use App\Services\Saas\PlanGuard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Base of the signed-in portal pages: the customer scope, module gates and the shared layout props. */
abstract class PortalController extends Controller
{
    protected function scope(): PortalScope
    {
        return new PortalScope(auth('portal')->user());
    }

    protected function company(Request $request): Company
    {
        return $request->attributes->get('portal_company');
    }

    /** 404 unless the company has this module on (hides the whole area, like a page that does not exist). */
    protected function needs(Request $request, string $module): void
    {
        abort_unless($this->has($request, $module), 404);
    }

    protected function has(Request $request, string $module): bool
    {
        return app(PlanGuard::class)->hasModule($this->company($request)->loadMissing('plan'), $module);
    }

    protected function page(Request $request, string $component, array $props = []): Response
    {
        $user = auth('portal')->user();

        return Inertia::render($component, $props + ['portal' => [
            'company' => ['id' => $this->company($request)->id, 'name' => $this->company($request)->name],
            'user' => ['name' => $user->name, 'email' => $user->email],
            'menu' => ['support' => $this->has($request, 'support'), 'contracts' => $this->has($request, 'contracts'), 'projects' => $this->has($request, 'projects')],
        ]]);
    }
}

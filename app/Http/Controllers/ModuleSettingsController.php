<?php

namespace App\Http\Controllers;

use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «الوحدات»: switch optional modules (factories, contracting, ...) on or off for the company. */
class ModuleSettingsController extends Controller
{
    public function __construct(protected TenantManager $tenantManager, protected PlanGuard $guard) {}

    public function index(): Response
    {
        $company = $this->tenantManager->getCompany()->loadMissing('plan');
        $off = $this->guard->disabledModules($company);

        return Inertia::render('Settings/Modules', [
            'modules' => collect(config('saas.optional_modules'))->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label, 'enabled' => ! in_array($key, $off, true), 'in_plan' => $this->guard->inPlan($company, $key),
            ])->values(),
        ]);
    }

    public function update(Request $request)
    {
        $keys = array_keys(config('saas.optional_modules'));
        $enabled = $request->validate(['enabled' => 'present|array', 'enabled.*' => Rule::in($keys)])['enabled'];

        $this->tenantManager->getCompany()->setSetting('disabled_modules', array_values(array_diff($keys, $enabled)), 'general', 'json');

        return back()->with('success', 'تم حفظ الوحدات المفعّلة');
    }
}

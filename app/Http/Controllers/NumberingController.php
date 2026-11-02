<?php

namespace App\Http\Controllers;

use App\Services\Documents\NumberingRules;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «دليل الترميز»: prefix / digits / start number of every numbered document and record.
 * A prefix that numbered a document is locked (see NumberingRules).
 */
class NumberingController extends Controller
{
    public const AREAS = [
        'sales' => 'المبيعات', 'purchases' => 'المشتريات', 'accounting' => 'المحاسبة', 'inventory' => 'المخزون',
        'parties' => 'العملاء والموردون والموظفون', 'work' => 'المصانع والمشاريع والمعدات',
    ];

    public function __construct(protected TenantManager $tenantManager, protected NumberingRules $rules, protected PlanGuard $guard) {}

    public function index(): Response
    {
        $company = $this->tenantManager->getCompany()->loadMissing('plan');
        $types = collect($this->rules->overview($company, fn ($module) => $this->guard->hasModule($company, $module)));

        return Inertia::render('Settings/Numbering', [
            'groups' => collect(self::AREAS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'types' => $types->where('area', $key)->values()])
                ->filter(fn ($g) => $g['types']->isNotEmpty())->values(),
            'year' => now()->year,
        ]);
    }

    public function update(Request $request, string $type, int $year)
    {
        $this->rules->save($this->tenantManager->getCompany(), $type, $year, $this->input($request));

        return back()->with('success', 'تم حفظ الترميز');
    }

    public function add(Request $request, string $type)
    {
        $year = (int) $request->input('year');
        $this->rules->addFromYear($this->tenantManager->getCompany(), $type, $year, $this->input($request));

        return back()->with('success', "تمت إضافة بادئة جديدة من سنة {$year}");
    }

    public function destroy(string $type, int $year)
    {
        $this->rules->destroy($this->tenantManager->getCompany(), $type, $year);

        return back()->with('success', 'تم حذف القاعدة');
    }

    protected function input(Request $request): array
    {
        return $request->validate([
            'prefix' => 'required|string|max:10',
            'pad' => 'required|integer|min:1|max:'.NumberingRules::MAX_PAD,
            'start_number' => 'required|integer|min:1|max:999999999',
            'with_year' => 'nullable|boolean',
        ]);
    }
}

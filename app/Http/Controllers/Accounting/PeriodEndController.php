<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Services\Accounting\PeriodEndService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Period end: month-end FX revaluation and the yearly zakat provision.
 */
class PeriodEndController extends Controller
{
    public function __construct(protected PeriodEndService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'fiscal_year_id' => 'nullable|tenant_exists:fiscal_years,id',
        ] + $this->zakatRules());

        $month = Carbon::createFromFormat('Y-m', $filters['month'] ?? now()->subMonthNoOverflow()->format('Y-m'))->endOfMonth();
        $years = FiscalYear::orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date', 'status']);
        $year = isset($filters['fiscal_year_id']) ? $years->firstWhere('id', (int) $filters['fiscal_year_id']) : ($years->firstWhere('status', '!=', 'closed') ?? $years->first());

        return Inertia::render('Accounting/PeriodEnd/Index', [
            'fx' => $this->service->fxPreview($month),
            'zakat' => $year ? $this->service->zakatPreview($year, $filters) : null,
            'years' => $years,
            'filters' => array_merge(['month' => $month->format('Y-m'), 'fiscal_year_id' => $year?->id, 'calendar' => 'gregorian', 'saudi_share' => 100, 'additions' => 0, 'deductions' => 0], $filters),
        ]);
    }

    public function revalue(Request $request)
    {
        $validated = $request->validate(['month' => 'required|date_format:Y-m']);
        $entry = $this->service->revalue(Carbon::createFromFormat('Y-m', $validated['month'])->endOfMonth());

        return back()->with($entry ? 'success' : 'info', $entry
            ? "تم ترحيل قيد إعادة التقييم {$entry->entry_number} وعكسه في أول الشهر التالي"
            : 'لا توجد أرصدة بعملات أجنبية تحتاج إعادة تقييم.');
    }

    public function zakat(Request $request)
    {
        $validated = $request->validate(['fiscal_year_id' => 'required|tenant_exists:fiscal_years,id'] + $this->zakatRules());
        $assessment = $this->service->postZakat(FiscalYear::findOrFail($validated['fiscal_year_id']), $validated);

        return back()->with('success', 'تم ترحيل مخصص الزكاة بمبلغ '.number_format((float) $assessment->amount, 2));
    }

    protected function zakatRules(): array
    {
        return [
            'calendar' => 'nullable|in:gregorian,hijri',
            'saudi_share' => 'nullable|numeric|min:0|max:100',
            'additions' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
        ];
    }
}

<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\YearEndClosing;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FiscalYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/FiscalYears/Index', [
            'years' => FiscalYear::with('periods')->orderByDesc('start_date')->get(),
        ]);
    }

    public function store(Request $request, FiscalCalendar $calendar)
    {
        $validated = $request->validate(['start_date' => 'required|date']);

        $start = Carbon::parse($validated['start_date'])->startOfMonth();
        $overlap = FiscalYear::where('start_date', '<=', $start->copy()->addYear()->subDay()->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlap) {
            return back()->withErrors(['start_date' => 'السنة المالية تتداخل مع سنة موجودة.']);
        }

        $calendar->createYear($start, app(TenantManager::class)->getTenantId());

        return back()->with('success', 'تم إنشاء السنة المالية وفتراتها الشهرية');
    }

    /**
     * Open / close / lock a monthly period.
     */
    public function updatePeriod(Request $request, FiscalPeriod $period)
    {
        $validated = $request->validate(['status' => 'required|in:open,closed,locked']);

        if ($period->status === 'locked') {
            return back()->with('error', 'الفترة مقفلة نهائياً ولا يمكن تغيير حالتها.');
        }
        if ($validated['status'] === 'open' && $period->fiscalYear->isClosed()) {
            return back()->with('error', 'السنة المالية مغلقة. أعد فتح السنة أولاً.');
        }

        $period->update(['status' => $validated['status']]);

        return back()->with('success', 'تم تحديث حالة الفترة');
    }

    public function close(FiscalYear $year, YearEndClosing $closing)
    {
        $entry = $closing->close($year);

        return back()->with('success', $entry
            ? "تم إقفال السنة المالية وترحيل صافي النتيجة للأرباح المحتجزة بالقيد {$entry->entry_number}"
            : 'تم إقفال السنة المالية');
    }

    public function reopen(FiscalYear $year, YearEndClosing $closing)
    {
        $closing->reopen($year);

        return back()->with('success', 'تم إعادة فتح السنة المالية وعكس قيد الإقفال');
    }
}

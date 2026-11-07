<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Services\Reports\CashForecastSnapshots;
use App\Services\Reports\ReportCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Accounting control reports: one landing page and one generic table page per report (same tables as the Excel / PDF export). */
class ControlReportsController extends Controller
{
    /** key => [title, description, filters] */
    public const REPORTS = [
        'controls-exceptions' => ['الاستثناءات المحاسبية وجودة البيانات', 'قيود مسودة، غير متوازنة، بلا مرجع، أسطر بلا بعد تحليلي مطلوب، وحسابات موقوفة عليها حركة.', []],
        'party-reconciliation' => ['مطابقة الأستاذ المساعد مع حسابات التحكم', 'رصيد العملاء والموردين المخزّن مقابل الدفتر وحسابات التحكم، والأطراف المختلفة.', []],
        'customer-balances' => ['أرصدة العملاء', 'أول المدة والحركة وآخر المدة وحد الائتمان وسندات القبض غير المطبقة.', ['range']],
        'supplier-balances' => ['أرصدة الموردين', 'أول المدة والحركة وآخر المدة وسندات الصرف غير المطبقة.', ['range']],
        'cash-movement' => ['حركة الصناديق والبنوك', 'أول المدة والوارد والصادر وآخر المدة لكل حساب، والحركات البنكية غير المسوّاة بأعمارها.', ['range']],
        'dormant-accounts' => ['الحسابات دون حركة', 'حسابات فعّالة لا حركة مرحّلة عليها خلال المدة المحددة.', ['days']],
        'budget-dimensions' => ['الموازنة حسب مركز التكلفة/المشروع/الفرع', 'سطور الموازنة المحددة لبعد مقابل الفعلي المسجَّل على القيود لنفس البعد، بالانحراف والنسبة.', ['range', 'dimension']],
        'forecast-vs-actual' => ['التدفق النقدي: المتوقع مقابل الفعلي', 'لقطات التوقع الأسبوعية (13 أسبوعًا) مقارنةً بالحركة النقدية الفعلية لكل أسبوع انقضى، ومتوسط دقة التوقع.', []],
        'closing-checklist' => ['قائمة تحقق إقفال الفترة', 'مسودات، فترات مفتوحة، حركات بنكية غير مسوّاة، إهلاك غير مرحّل، ضريبة غير مسوّاة، فروق مطابقة، موافقات معلّقة وقيود متكررة مستحقة — بحالة كل بند.', ['fiscal_year']],
        'approval-flow' => ['تدفق طلبات الموافقة', 'طلبات الاعتماد حسب نوع المستند أو المعتمِد: المعلّق والمتأخر وأقدم عمر والمرفوض ومتوسط زمن القرار.', ['approval']],
        'unusual-expenses' => ['المصروفات غير المعتادة', 'حسابات مصروف تجاوز صرف الشهر الحالي فيها المتوسط التاريخي + عدد من الانحرافات المعيارية (إشارة مراجعة لا حكم).', ['unusual']],
    ];

    public function index(): Response
    {
        return Inertia::render('Accounting/Controls/Index', [
            'reports' => collect(self::REPORTS)->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'description' => $r[1]])->values(),
        ]);
    }

    /** Keep today's cash forecast (the scheduler does so every Monday). */
    public function forecastSnapshot(CashForecastSnapshots $snapshots)
    {
        $snapshots->take(null, auth()->id());

        return back()->with('success', 'تم حفظ لقطة التوقع');
    }

    public function show(Request $request, string $report, ReportCatalog $catalog): Response
    {
        abort_unless(isset(self::REPORTS[$report]), 404);

        return Inertia::render('Accounting/Controls/Show', [
            'report' => $report,
            'table' => $catalog->build($report, $request),
            'filterKinds' => self::REPORTS[$report][2],
            'years' => in_array('fiscal_year', self::REPORTS[$report][2], true) ? FiscalYear::orderByDesc('start_date')->get(['id', 'name']) : [],
            'filters' => [
                'start_date' => $request->input('start_date', now()->startOfYear()->toDateString()),
                'end_date' => $request->input('end_date', now()->toDateString()),
                'days' => (int) ($request->input('days') ?: 180),
                'fiscal_year_id' => $request->integer('fiscal_year_id') ?: null,
                'group' => $request->input('group') === 'approver' ? 'approver' : 'type',
                'overdue_days' => (int) ($request->input('overdue_days') ?: 7),
                'dimension' => in_array($request->input('dimension'), ['cost_center', 'project', 'branch'], true) ? $request->input('dimension') : 'cost_center',
                'months' => (int) ($request->input('months') ?: 12),
                'k' => (float) ($request->input('k') ?: 2),
                'min_amount' => (float) $request->input('min_amount', 0),
            ],
        ]);
    }
}

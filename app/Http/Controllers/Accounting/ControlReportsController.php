<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
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
    ];

    public function index(): Response
    {
        return Inertia::render('Accounting/Controls/Index', [
            'reports' => collect(self::REPORTS)->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'description' => $r[1]])->values(),
        ]);
    }

    public function show(Request $request, string $report, ReportCatalog $catalog): Response
    {
        abort_unless(isset(self::REPORTS[$report]), 404);

        return Inertia::render('Accounting/Controls/Show', [
            'report' => $report,
            'table' => $catalog->build($report, $request),
            'filterKinds' => self::REPORTS[$report][2],
            'filters' => [
                'start_date' => $request->input('start_date', now()->startOfYear()->toDateString()),
                'end_date' => $request->input('end_date', now()->toDateString()),
                'days' => (int) ($request->input('days') ?: 180),
            ],
        ]);
    }
}

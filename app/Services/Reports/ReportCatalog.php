<?php

namespace App\Services\Reports;

use App\Models\Supplier;
use App\Services\Accounting\AccountingReports;
use App\Services\Purchases\SupplierStatement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The reports that can be downloaded as Excel / PDF. Each builder reads the same service the report page
 * uses (so the numbers always agree) and returns a flat table:
 *   ['title', 'subtitle', 'columns' => [['key', 'label', 'type' => text|money|number]], 'rows' => [...], 'bold' => [row indexes]]
 * A row is an associative array by column key.
 */
class ReportCatalog
{
    /** report key => permission that allows downloading it */
    public const PERMISSIONS = [
        'income-statement' => 'accounting.statements.export',
        'balance-sheet' => 'accounting.statements.export',
        'cash-flow' => 'accounting.statements.export',
        'trial-balance' => 'accounting.trial_balance.export',
        'aging' => 'accounting.aging.export',
        'vat-return' => 'accounting.vat_return.export',
        'sales-analysis' => 'reports.sales.export',
        'purchases-analysis' => 'reports.purchases.export',
        'supplier-statement' => 'purchases.suppliers.export',
    ];

    public function __construct(protected AccountingReports $reports) {}

    public function keys(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public function build(string $key, Request $request): array
    {
        return match ($key) {
            'income-statement' => $this->incomeStatement($request),
            'balance-sheet' => $this->balanceSheet($request),
            'cash-flow' => $this->cashFlow($request),
            'trial-balance' => $this->trialBalance($request),
            'aging' => $this->aging($request),
            'vat-return' => $this->vatReturn($request),
            'sales-analysis' => $this->analysis($request, 'sales'),
            'purchases-analysis' => $this->analysis($request, 'purchases'),
            'supplier-statement' => $this->supplierStatement($request),
        };
    }

    protected function range(Request $request, ?Carbon $defaultFrom = null, ?Carbon $defaultTo = null): array
    {
        $v = $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date']);

        return [
            Carbon::parse($v['start_date'] ?? ($defaultFrom ?? now()->startOfYear())->toDateString()),
            Carbon::parse($v['end_date'] ?? ($defaultTo ?? now())->toDateString()),
        ];
    }

    protected function incomeStatement(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $costCenterId = $request->validate(['cost_center_id' => 'nullable|tenant_exists:cost_centers,id'])['cost_center_id'] ?? null;
        if (($mode = $request->input('compare')) && in_array($mode, ReportComparison::MODES, true)) {
            $c = app(ReportComparison::class)->incomeStatement($from, $to, $mode, $costCenterId);

            return $this->comparisonTable('قائمة الدخل', $this->period($from, $to).' — مقارنة بـ'.$c['label'], $c['rows'], 'الفترة الحالية', $c['label']);
        }
        $r = $this->reports->incomeStatement($from, $to, $costCenterId);

        $rows = [];
        $bold = [];
        $section = function (string $label, array $block, string $totalLabel) use (&$rows, &$bold) {
            $rows[] = ['code' => '', 'name' => $label, 'amount' => null];
            $bold[] = count($rows) - 1;
            foreach ($block['rows'] as $row) {
                $rows[] = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $row['amount']];
            }
            $rows[] = ['code' => '', 'name' => $totalLabel, 'amount' => $block['total']];
            $bold[] = count($rows) - 1;
        };
        $section('الإيرادات', $r['revenue'], 'إجمالي الإيرادات');
        $section('المصروفات', $r['expense'], 'إجمالي المصروفات');
        foreach ([['تكلفة المبيعات', $r['cost_of_sales']], ['مجمل الربح', $r['gross_profit']], ['صافي الربح', $r['net_profit']]] as [$label, $value]) {
            $rows[] = ['code' => '', 'name' => $label, 'amount' => $value];
            $bold[] = count($rows) - 1;
        }

        return $this->table('قائمة الدخل', $this->period($from, $to), [['code', 'الرمز'], ['name', 'الحساب'], ['amount', 'المبلغ', 'money']], $rows, $bold);
    }

    protected function balanceSheet(Request $request): array
    {
        $asOf = Carbon::parse($request->validate(['as_of' => 'nullable|date'])['as_of'] ?? now()->toDateString());
        if (($mode = $request->input('compare')) && in_array($mode, ReportComparison::MODES, true)) {
            $c = app(ReportComparison::class)->balanceSheet($asOf, $mode);

            return $this->comparisonTable('الميزانية العمومية', 'كما في '.$asOf->toDateString().' — مقارنة بـ'.$c['label'], $c['rows'], 'كما في '.$asOf->toDateString(), $c['label']);
        }
        $r = $this->reports->balanceSheet($asOf);

        $rows = [];
        $bold = [];
        $section = function (string $label, array $block, string $totalLabel, float $total) use (&$rows, &$bold) {
            $rows[] = ['code' => '', 'name' => $label, 'amount' => null];
            $bold[] = count($rows) - 1;
            foreach ($block['rows'] as $row) {
                $rows[] = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $row['amount']];
            }
            $rows[] = ['code' => '', 'name' => $totalLabel, 'amount' => $total];
            $bold[] = count($rows) - 1;
        };
        $section('الأصول', $r['assets'], 'إجمالي الأصول', $r['total_assets']);
        $section('الالتزامات', $r['liabilities'], 'إجمالي الالتزامات', $r['total_liabilities']);
        $section('حقوق الملكية', $r['equity'], 'إجمالي حقوق الملكية', $r['total_equity']);
        $rows[] = ['code' => '', 'name' => 'إجمالي الالتزامات وحقوق الملكية', 'amount' => $r['total_liabilities_equity']];
        $bold[] = count($rows) - 1;

        return $this->table('الميزانية العمومية', 'كما في '.$asOf->toDateString(), [['code', 'الرمز'], ['name', 'الحساب'], ['amount', 'المبلغ', 'money']], $rows, $bold);
    }

    protected function cashFlow(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $r = $request->input('method') === 'indirect' ? $this->reports->cashFlowIndirect($from, $to) : $this->reports->cashFlow($from, $to);

        $rows = [];
        $bold = [];
        $labels = ['operating' => 'الأنشطة التشغيلية', 'investing' => 'الأنشطة الاستثمارية', 'financing' => 'الأنشطة التمويلية'];
        foreach ($r['sections'] as $category => $section) {
            $rows[] = ['name' => $labels[$category] ?? $category, 'amount' => $section['total']];
            $bold[] = count($rows) - 1;
            foreach ($section['rows'] as $row) {
                $rows[] = ['name' => $row['name'], 'amount' => $row['amount']];
            }
        }
        foreach ([['رصيد النقد أول الفترة', $r['opening_cash']], ['صافي التغير في النقد', $r['net_change']], ['رصيد النقد آخر الفترة', $r['closing_cash']]] as [$label, $value]) {
            $rows[] = ['name' => $label, 'amount' => $value];
            $bold[] = count($rows) - 1;
        }

        return $this->table('قائمة التدفقات النقدية', $this->period($from, $to), [['name', 'البند'], ['amount', 'المبلغ', 'money']], $rows, $bold);
    }

    protected function trialBalance(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $v = $request->validate(['level' => 'nullable|integer|min:1|max:10', 'show_zero' => 'nullable|boolean', 'cost_center_id' => 'nullable|tenant_exists:cost_centers,id']);
        $r = $this->reports->trialBalance($from, $to, ! ($v['show_zero'] ?? false), $v['level'] ?? null, $v['cost_center_id'] ?? null);

        $rows = array_map(fn ($row) => ['code' => $row['code'], 'name' => $row['name']] + array_intersect_key($row, array_flip(['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'])), $r['rows']);
        $rows[] = ['code' => '', 'name' => 'الإجمالي'] + $r['totals'];

        return $this->table('ميزان المراجعة', $this->period($from, $to), [
            ['code', 'الرمز'], ['name', 'الحساب'], ['opening_debit', 'افتتاحي مدين', 'money'], ['opening_credit', 'افتتاحي دائن', 'money'],
            ['debit', 'حركة مدينة', 'money'], ['credit', 'حركة دائنة', 'money'], ['closing_debit', 'ختامي مدين', 'money'], ['closing_credit', 'ختامي دائن', 'money'],
        ], $rows, [count($rows) - 1]);
    }

    protected function aging(Request $request): array
    {
        $v = $request->validate(['kind' => 'nullable|in:receivables,payables', 'as_of' => 'nullable|date']);
        $kind = $v['kind'] ?? 'receivables';
        $asOf = Carbon::parse($v['as_of'] ?? now()->toDateString());
        $r = $this->reports->aging($kind, $asOf);

        $rows = collect($r['rows'])->map(fn ($row) => collect($row)->only(['name', 'current', 'd30', 'd60', 'd90', 'd90plus', 'total'])->all())->all();
        $rows[] = ['name' => 'الإجمالي'] + $r['totals'];

        return $this->table($kind === 'payables' ? 'أعمار الدائنين' : 'أعمار المدينين', 'كما في '.$asOf->toDateString(), [
            ['name', $kind === 'payables' ? 'المورد' : 'العميل'], ['current', 'جارٍ', 'money'], ['d30', '1–30', 'money'], ['d60', '31–60', 'money'], ['d90', '61–90', 'money'], ['d90plus', 'أكثر من 90', 'money'], ['total', 'الإجمالي', 'money'],
        ], $rows, [count($rows) - 1]);
    }

    protected function vatReturn(Request $request): array
    {
        $quarter = now()->firstOfQuarter();
        [$from, $to] = $this->range($request, $quarter, $quarter->copy()->lastOfQuarter());
        $r = $this->reports->vatReturn($from, $to);

        $rows = array_map(fn ($b) => ['no' => $b['no'], 'label' => $b['label'], 'amount' => $b['amount'], 'adjustment' => $b['adjustment'], 'vat' => $b['vat']], $r['boxes']);
        $rows[] = ['no' => '', 'label' => 'ضريبة المخرجات', 'amount' => null, 'adjustment' => null, 'vat' => $r['output_vat']];
        $rows[] = ['no' => '', 'label' => 'ضريبة المدخلات', 'amount' => null, 'adjustment' => null, 'vat' => $r['input_vat']];
        $rows[] = ['no' => '', 'label' => 'صافي الضريبة المستحقة', 'amount' => null, 'adjustment' => null, 'vat' => $r['net_vat']];

        return $this->table('إقرار ضريبة القيمة المضافة', $this->period($from, $to), [['no', 'الخانة'], ['label', 'البيان'], ['amount', 'المبلغ', 'money'], ['adjustment', 'التعديلات', 'money'], ['vat', 'الضريبة', 'money']], $rows, [count($rows) - 1, count($rows) - 2, count($rows) - 3]);
    }

    /** Statement lines with the earlier period beside the current one. */
    protected function comparisonTable(string $title, string $subtitle, array $lines, string $currentLabel, string $previousLabel): array
    {
        $bold = [];
        foreach ($lines as $i => $line) {
            if ($line['bold']) {
                $bold[] = $i;
            }
        }

        return $this->table($title, $subtitle, [['code', 'الرمز'], ['name', 'الحساب'], ['current', $currentLabel, 'money'], ['previous', $previousLabel, 'money'], ['change', 'الفرق', 'money'], ['pct', '%']], $lines, $bold);
    }

    protected function supplierStatement(Request $request): array
    {
        $v = $request->validate(['supplier_id' => 'required|tenant_exists:suppliers,id', 'start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date']);
        $supplier = Supplier::findOrFail($v['supplier_id']);
        $from = ! empty($v['start_date']) ? Carbon::parse($v['start_date']) : null;
        $to = ! empty($v['end_date']) ? Carbon::parse($v['end_date']) : null;
        $r = app(SupplierStatement::class)->build($supplier, $from, $to);

        $rows = $r['transactions']->map(fn ($t) => ['date' => $t['date'], 'type_ar' => $t['type_ar'], 'reference' => $t['reference'], 'notes' => $t['notes'], 'debit' => $t['debit'], 'credit' => $t['credit'], 'balance' => $t['balance']])->all();
        $rows[] = ['date' => '', 'type_ar' => 'الرصيد الختامي (المستحق للمورد)', 'reference' => '', 'notes' => '', 'debit' => null, 'credit' => null, 'balance' => $r['closing']];

        return $this->table('كشف حساب المورد: '.$supplier->name, ($from ? 'من '.$from->toDateString().' ' : '').'إلى '.($to ?? now())->toDateString(), [
            ['date', 'التاريخ'], ['type_ar', 'النوع'], ['reference', 'المرجع'], ['notes', 'البيان'], ['debit', 'مدين (دفع/مرتجع)', 'money'], ['credit', 'دائن (فاتورة)', 'money'], ['balance', 'الرصيد', 'money'],
        ], $rows, [count($rows) - 1]);
    }

    protected function analysis(Request $request, string $kind): array
    {
        [$from, $to] = $this->range($request);
        $allowed = $kind === 'sales' ? TradeAnalysis::SALES : TradeAnalysis::PURCHASES;
        $by = in_array($request->input('by'), $allowed, true) ? $request->input('by') : $allowed[0];
        $r = $kind === 'sales' ? app(TradeAnalysis::class)->sales($by, $from, $to) : app(TradeAnalysis::class)->purchases($by, $from, $to);
        $labels = ['customer' => 'العميل', 'supplier' => 'المورد', 'item' => 'الصنف', 'salesperson' => 'المندوب', 'branch' => 'الفرع'];

        $rows = array_map(fn ($row) => ['name' => $row['name'], 'documents' => $row['documents'], 'quantity' => $row['quantity'], 'amount' => $row['amount'], 'share' => $row['share']], $r['rows']);
        $rows[] = ['name' => 'الإجمالي', 'documents' => $r['total']['documents'], 'quantity' => $r['total']['quantity'], 'amount' => $r['total']['amount'], 'share' => null];

        return $this->table(($kind === 'sales' ? 'المبيعات حسب ' : 'المشتريات حسب ').$labels[$by], $this->period($from, $to).' — قبل الضريبة'.($by === 'item' ? '، قبل المرتجعات' : '، صافي المرتجعات'), [
            ['name', $labels[$by]], ['documents', 'المستندات'], ['quantity', 'الكمية', 'number'], ['amount', 'المبلغ', 'money'], ['share', '% من الإجمالي'],
        ], $rows, [count($rows) - 1]);
    }

    protected function period(Carbon $from, Carbon $to): string
    {
        return 'من '.$from->toDateString().' إلى '.$to->toDateString();
    }

    /** @param array<int, array{0: string, 1: string, 2?: string}> $columns */
    protected function table(string $title, string $subtitle, array $columns, array $rows, array $bold = []): array
    {
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? 'text'], $columns),
            'rows' => array_values($rows),
            'bold' => $bold,
        ];
    }
}

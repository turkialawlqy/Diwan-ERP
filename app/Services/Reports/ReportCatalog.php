<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountingControls;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\SourceDocument;
use App\Services\Purchases\SupplierStatement;
use App\Services\Tax\WithholdingReport;
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
        'withholding' => 'accounting.vat_return.export',
        'ledger' => 'accounting.ledger.export',
        // Control reports (AccountingControls).
        'controls-exceptions' => 'accounting.controls.export',
        'party-reconciliation' => 'accounting.controls.export',
        'customer-balances' => 'accounting.controls.export',
        'supplier-balances' => 'accounting.controls.export',
        'cash-movement' => 'accounting.controls.export',
        'dormant-accounts' => 'accounting.controls.export',
        'unusual-expenses' => 'accounting.controls.export',
        'cash-forecast' => 'accounting.controls.export',
        // Transaction lists (one row per document / journal line), for hand-over and analysis in Excel.
        'tx-sales-invoices' => 'sales.invoices.export',
        'tx-purchase-invoices' => 'purchases.invoices.export',
        'tx-customer-payments' => 'sales.payments.export',
        'tx-supplier-payments' => 'purchases.payments.export',
        'tx-expenses' => 'finance.expenses.export',
        'tx-journal-lines' => 'accounting.journals.export',
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
            'cash-forecast' => $this->cashForecast($request),
            'unusual-expenses' => $this->controlUnusual($request),
            'vat-return' => $this->vatReturn($request),
            'sales-analysis' => $this->analysis($request, 'sales'),
            'purchases-analysis' => $this->analysis($request, 'purchases'),
            'supplier-statement' => $this->supplierStatement($request),
            'withholding' => $this->withholding($request),
            'ledger' => $this->ledger($request),
            'controls-exceptions' => $this->controlExceptions(),
            'party-reconciliation' => $this->controlReconciliation(),
            'customer-balances' => $this->controlBalances($request, 'customer'),
            'supplier-balances' => $this->controlBalances($request, 'supplier'),
            'cash-movement' => $this->controlCash($request),
            'dormant-accounts' => $this->controlDormant($request),
            'tx-sales-invoices' => $this->documents($request, 'فواتير المبيعات', Invoice::class, 'invoice_date', 'customer', 'invoice_number', ['subtotal', 'tax_amount', 'total', 'paid_amount', 'remaining_amount']),
            'tx-purchase-invoices' => $this->documents($request, 'فواتير المشتريات', PurchaseInvoice::class, 'invoice_date', 'supplier', 'invoice_number', ['subtotal', 'tax_amount', 'total', 'paid_amount', 'remaining_amount']),
            'tx-customer-payments' => $this->documents($request, 'سندات القبض', CustomerPayment::class, 'payment_date', 'customer', 'payment_number', ['amount']),
            'tx-supplier-payments' => $this->documents($request, 'سندات الصرف للموردين', SupplierPayment::class, 'payment_date', 'supplier', 'payment_number', ['amount']),
            'tx-expenses' => $this->expenses($request),
            'tx-journal-lines' => $this->journalLines($request),
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
        if (($mode = $request->input('compare')) && in_array($mode, ReportComparison::INCOME_MODES, true)) {
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
        $l = $r['liquidity'];
        $rows[] = ['code' => '', 'name' => 'منها: الأصول المتداولة / غير المتداولة', 'amount' => null];
        $bold[] = count($rows) - 1;
        foreach ([['الأصول المتداولة', $l['assets']['current']], ['الأصول غير المتداولة', $l['assets']['non_current']], ['الخصوم المتداولة', $l['liabilities']['current']], ['الخصوم غير المتداولة', $l['liabilities']['non_current']]] as [$name, $amount]) {
            $rows[] = ['code' => '', 'name' => $name, 'amount' => $amount];
        }
        if ($l['current_ratio'] !== null) {
            $rows[] = ['code' => '', 'name' => 'نسبة التداول (أصول متداولة ÷ خصوم متداولة)', 'amount' => $l['current_ratio']];
        }

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
        $v = $request->validate(['kind' => 'nullable|in:receivables,payables', 'as_of' => 'nullable|date', 'buckets' => ['nullable', 'regex:/^\d{1,4}(,\d{1,4}){0,5}$/']]);
        $kind = $v['kind'] ?? 'receivables';
        $asOf = Carbon::parse($v['as_of'] ?? now()->toDateString());
        $r = $this->reports->aging($kind, $asOf, isset($v['buckets']) ? explode(',', $v['buckets']) : [30, 60, 90]);

        $keys = array_merge(array_column($r['columns'], 0), ['total'], $r['past'] ? ['collected_after'] : []);
        $rows = collect($r['rows'])->map(fn ($row) => collect($row)->only(array_merge(['name'], $keys))->all())->all();
        $rows[] = ['name' => 'الإجمالي'] + collect($r['totals'])->only($keys)->all();

        $columns = [['name', $kind === 'payables' ? 'المورد' : 'العميل']];
        foreach ($r['columns'] as [$key, $label]) {
            $columns[] = [$key, $label, 'money'];
        }
        $columns[] = ['total', 'الإجمالي', 'money'];
        if ($r['past']) {
            $columns[] = ['collected_after', 'حُصِّل بعد التاريخ', 'money'];
        }

        return $this->table($kind === 'payables' ? 'أعمار الدائنين' : 'أعمار المدينين', 'كما في '.$asOf->toDateString(), $columns, $rows, [count($rows) - 1]);
    }

    protected function cashForecast(Request $request): array
    {
        $v = $request->validate(['collect_delay_days' => 'nullable|integer|min:0|max:365', 'collect_pct' => 'nullable|numeric|min:0|max:100', 'pay_delay_days' => 'nullable|integer|min:0|max:365']);
        $f = app(Insights::class)->cashForecast(13, null, $v);
        $sc = $f['scenario'];
        $rows = array_map(fn ($w) => $w + ['period' => $w['start'].' → '.$w['end']], $f['weeks']);
        $rows[] = ['period' => 'الرصيد الافتتاحي: '.number_format($f['opening'], 2, '.', ''), 'balance' => $f['closing']];

        return $this->table('التدفق النقدي المتوقع (13 أسبوعًا)', 'سيناريو: تأخير التحصيل '.$sc['collect_delay_days'].' يوم، نسبة التحصيل '.$sc['collect_pct'].'%، تأجيل السداد '.$sc['pay_delay_days'].' يوم', [
            ['period', 'الأسبوع'], ['receivables', 'تحصيل فواتير', 'money'], ['recurring', 'فواتير دورية', 'money'], ['cheques_in', 'شيكات واردة', 'money'],
            ['payables', 'موردون', 'money'], ['cheques_out', 'شيكات صادرة', 'money'], ['payroll', 'رواتب', 'money'], ['net', 'الصافي', 'money'], ['balance', 'الرصيد', 'money'],
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

    protected function withholding(Request $request): array
    {
        $quarter = now()->firstOfQuarter();
        [$from, $to] = $this->range($request, $quarter, $quarter->copy()->lastOfQuarter());
        $r = app(WithholdingReport::class)->build($from, $to);

        $rows = $r['rows'];
        $rows[] = ['date' => '', 'number' => '', 'supplier' => 'الإجمالي', 'country' => '', 'category' => '', 'gross' => $r['totals']['gross'], 'rate' => null, 'withheld' => $r['totals']['withheld']];

        return $this->table('إقرار ضريبة الاستقطاع', $this->period($from, $to), [
            ['date', 'التاريخ'], ['number', 'السند'], ['supplier', 'المورد'], ['country', 'الدولة'], ['category', 'نوع الدفعة'], ['gross', 'المبلغ الإجمالي', 'money'], ['rate', 'النسبة %'], ['withheld', 'المستقطع', 'money'],
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

    /** A dated document list with its money columns summed in a closing row. */
    protected function documents(Request $request, string $title, string $model, string $dateColumn, string $partyRelation, string $numberColumn, array $money): array
    {
        [$from, $to] = $this->range($request);
        $labels = ['subtotal' => 'قبل الضريبة', 'tax_amount' => 'الضريبة', 'total' => 'الإجمالي', 'paid_amount' => 'المدفوع', 'remaining_amount' => 'المتبقي', 'amount' => 'المبلغ'];
        $query = $model::query()->with($partyRelation.':id,name')->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to)->orderBy($dateColumn)->orderBy('id');
        $totals = array_fill_keys($money, 0.0);
        $rows = $query->limit(20000)->get()->map(function ($d) use ($dateColumn, $partyRelation, $numberColumn, $money, &$totals) {
            $row = ['number' => $d->{$numberColumn}, 'date' => substr((string) $d->getRawOriginal($dateColumn), 0, 10), 'party' => $d->{$partyRelation}?->name ?? '—'];
            foreach ($money as $m) {
                $row[$m] = (float) $d->{$m};
                $totals[$m] += (float) $d->{$m};
            }

            return $row;
        })->all();
        $rows[] = ['number' => 'الإجمالي', 'date' => '', 'party' => ''] + array_map(fn ($v) => round($v, 2), $totals);

        return $this->table($title, $this->period($from, $to), array_merge([['number', 'الرقم'], ['date', 'التاريخ'], ['party', $partyRelation === 'customer' ? 'العميل' : 'المورد']],
            array_map(fn ($m) => [$m, $labels[$m], 'money'], $money)), $rows, [count($rows) - 1]);
    }

    protected function expenses(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $total = 0.0;
        $rows = Expense::query()->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)->orderBy('expense_date')->orderBy('id')->limit(20000)->get()
            ->map(function ($e) use (&$total) {
                $total += (float) $e->total_amount;

                return ['number' => $e->expense_number, 'date' => substr((string) $e->getRawOriginal('expense_date'), 0, 10), 'title' => $e->title, 'category' => $e->category, 'vendor' => $e->vendor_name ?? '',
                    'amount' => (float) $e->amount, 'tax' => (float) $e->tax_amount, 'total' => (float) $e->total_amount];
            })->all();
        $rows[] = ['number' => 'الإجمالي', 'date' => '', 'title' => '', 'category' => '', 'vendor' => '', 'amount' => null, 'tax' => null, 'total' => round($total, 2)];

        return $this->table('سندات المصروفات', $this->period($from, $to), [['number', 'الرقم'], ['date', 'التاريخ'], ['title', 'البيان'], ['category', 'التصنيف'], ['vendor', 'المورد'],
            ['amount', 'المبلغ', 'money'], ['tax', 'الضريبة', 'money'], ['total', 'الإجمالي', 'money']], $rows, [count($rows) - 1]);
    }

    protected function journalLines(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $debit = $credit = 0.0;
        $rows = JournalEntryLine::query()->with(['journalEntry:id,entry_number,status', 'account:id,code,name_ar', 'costCenter:id,code'])
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalEntry::STATUS_POSTED))
            ->orderBy('date')->orderBy('id')->limit(50000)->get()
            ->map(function ($l) use (&$debit, &$credit) {
                $debit += (float) $l->debit;
                $credit += (float) $l->credit;

                return ['entry' => $l->journalEntry?->entry_number, 'date' => substr((string) $l->getRawOriginal('date'), 0, 10), 'code' => $l->account?->code, 'account' => $l->account?->name_ar,
                    'cost_center' => $l->costCenter?->code ?? '', 'description' => $l->description, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit];
            })->all();
        $rows[] = ['entry' => 'الإجمالي', 'date' => '', 'code' => '', 'account' => '', 'cost_center' => '', 'description' => '', 'debit' => round($debit, 2), 'credit' => round($credit, 2)];

        return $this->table('سطور القيود المرحّلة', $this->period($from, $to), [['entry', 'القيد'], ['date', 'التاريخ'], ['code', 'كود الحساب'], ['account', 'الحساب'], ['cost_center', 'مركز التكلفة'],
            ['description', 'البيان'], ['debit', 'مدين', 'money'], ['credit', 'دائن', 'money']], $rows, [count($rows) - 1]);
    }

    protected function controlExceptions(): array
    {
        $rows = [];
        $bold = [];
        $total = 0;
        foreach (app(AccountingControls::class)->exceptions() as $section) {
            $rows[] = ['reference' => $section['label'].' ('.count($section['rows']).')', 'date' => '', 'description' => '', 'age' => null, 'who' => '', 'detail' => ''];
            $bold[] = count($rows) - 1;
            foreach ($section['rows'] as $row) {
                $rows[] = $row;
            }
            $total += count($section['rows']);
        }

        return $this->table('الاستثناءات المحاسبية وجودة البيانات', "{$total} بند يحتاج مراجعة", [
            ['reference', 'القيد / البند'], ['date', 'التاريخ'], ['description', 'البيان'], ['age', 'العمر (يوم)', 'number'], ['who', 'المنشئ'], ['detail', 'ملاحظة'],
        ], $rows, $bold);
    }

    protected function controlReconciliation(): array
    {
        $r = app(AccountingControls::class)->partyReconciliation();
        $rows = [];
        $bold = [];
        foreach ($r['control'] as $c) {
            $rows[] = ['name' => 'حساب التحكم: '.$c['label'], 'a' => $c['control'], 'b' => $c['tagged'], 'c' => $c['untagged'], 'd' => $c['stored'], 'e' => $c['difference']];
            $bold[] = count($rows) - 1;
        }
        foreach ($r['parties'] as $p) {
            $rows[] = ['name' => ($p['kind'] === 'customer' ? 'عميل: ' : 'مورد: ').$p['name'], 'a' => $p['ledger'], 'b' => null, 'c' => null, 'd' => $p['stored'], 'e' => $p['difference']];
        }

        return $this->table('مطابقة الأستاذ المساعد مع حسابات التحكم', count($r['parties']).' طرف بفرق بين الرصيد المخزّن والدفتر', [
            ['name', 'البند'], ['a', 'رصيد الدفتر', 'money'], ['b', 'منه منسوب لأطراف', 'money'], ['c', 'بلا طرف', 'money'], ['d', 'الرصيد المخزّن', 'money'], ['e', 'الفرق (المخزّن − الدفتر)', 'money'],
        ], $rows, $bold);
    }

    protected function controlBalances(Request $request, string $kind): array
    {
        [$from, $to] = $this->range($request);
        $data = app(AccountingControls::class)->partyBalances($kind, $from, $to);
        $customers = $kind === 'customer';
        $rows = array_map(fn ($r) => ['name' => $r['name'], 'opening' => $r['opening'], 'debit' => $r['debit'], 'credit' => $r['credit'], 'closing' => $r['closing'],
            'limit' => $r['limit'], 'over' => $r['over_limit'] ? 'تجاوز' : '', 'unapplied' => $r['unapplied']], $data);
        $sum = fn ($k) => round(array_sum(array_column($data, $k)), 2);
        $rows[] = ['name' => 'الإجمالي', 'opening' => $sum('opening'), 'debit' => $sum('debit'), 'credit' => $sum('credit'), 'closing' => $sum('closing'), 'limit' => null, 'over' => '', 'unapplied' => $sum('unapplied')];

        $columns = [['name', $customers ? 'العميل' : 'المورد'], ['opening', 'أول المدة', 'money'], ['debit', 'مدين', 'money'], ['credit', 'دائن', 'money'], ['closing', 'آخر المدة', 'money']];
        if ($customers) {
            array_push($columns, ['limit', 'حد الائتمان', 'money'], ['over', 'تجاوز الحد']);
        }
        $columns[] = ['unapplied', $customers ? 'سندات قبض غير مطبقة' : 'سندات صرف غير مطبقة', 'money'];

        return $this->table($customers ? 'أرصدة العملاء' : 'أرصدة الموردين', $this->period($from, $to), $columns, $rows, [count($rows) - 1]);
    }

    protected function controlCash(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $d = app(AccountingControls::class)->cashMovement($from, $to);
        $rows = array_map(fn ($a) => ['name' => $a['name'], 'type' => $a['type'], 'opening' => $a['opening'], 'in' => $a['in'], 'out' => $a['out'], 'closing' => $a['closing']], $d['accounts']);
        $rows[] = ['name' => 'الإجمالي', 'type' => '', 'opening' => round(array_sum(array_column($d['accounts'], 'opening')), 2), 'in' => round(array_sum(array_column($d['accounts'], 'in')), 2),
            'out' => round(array_sum(array_column($d['accounts'], 'out')), 2), 'closing' => round(array_sum(array_column($d['accounts'], 'closing')), 2)];
        $bold = [count($rows) - 1];
        $rows[] = ['name' => 'حركات بنكية غير مسوّاة (حسب العمر)', 'type' => 'عدد الحركات', 'opening' => null, 'in' => null, 'out' => null, 'closing' => 'صافي'];
        $bold[] = count($rows) - 1;
        foreach ($d['unreconciled'] as $u) {
            $rows[] = ['name' => $u['label'], 'type' => $u['count'], 'opening' => null, 'in' => null, 'out' => null, 'closing' => $u['net']];
        }

        return $this->table('حركة الصناديق والبنوك', $this->period($from, $to), [
            ['name', 'الحساب'], ['type', 'النوع'], ['opening', 'أول المدة', 'money'], ['in', 'وارد', 'money'], ['out', 'صادر', 'money'], ['closing', 'آخر المدة', 'money'],
        ], $rows, $bold);
    }

    protected function controlDormant(Request $request): array
    {
        $days = max(1, min(3650, (int) ($request->input('days') ?: 180)));
        $data = app(AccountingControls::class)->dormantAccounts($days);

        return $this->table('الحسابات دون حركة', "لا حركة مرحّلة منذ {$days} يومًا — ".count($data).' حساب', [
            ['code', 'الرمز'], ['name', 'الحساب'], ['last', 'آخر حركة'], ['days', 'منذ (يوم)', 'number'], ['balance', 'الرصيد', 'money'],
        ], $data);
    }

    protected function controlUnusual(Request $request): array
    {
        $months = max(3, min(36, (int) ($request->input('months') ?: 12)));
        $k = max(0.5, min(6.0, (float) ($request->input('k') ?: 2)));
        $min = max(0.0, (float) $request->input('min_amount', 0));
        $data = app(AccountingControls::class)->unusualExpenses($months, $k, $min);

        return $this->table('المصروفات غير المعتادة', 'شهر '.now()->format('Y-m')." مقابل متوسط آخر {$months} شهرًا + {$k}×الانحراف المعياري — ".count($data).' حساب للمراجعة', [
            ['code', 'الرمز'], ['name', 'الحساب'], ['month', 'الشهر'], ['amount', 'مصروف الشهر', 'money'], ['mean', 'المتوسط', 'money'], ['std', 'الانحراف', 'money'], ['threshold', 'الحد المتوقع', 'money'], ['excess', 'الزيادة عن المتوسط', 'money'],
        ], $data);
    }

    /** One account's ledger with its running balance, the entry and the document each line came from. */
    protected function ledger(Request $request): array
    {
        $request->validate(['account_id' => 'required|tenant_exists:accounts,id', 'cost_center_id' => 'nullable|tenant_exists:cost_centers,id']);
        [$from, $to] = $this->range($request);
        $account = Account::findOrFail($request->integer('account_id'));
        $ledger = $this->reports->ledger($account, $from, $to, 20000, null, null, $request->integer('cost_center_id') ?: null);
        $sources = app(SourceDocument::class);

        $rows = [['date' => '', 'entry' => 'رصيد أول المدة', 'description' => '', 'source' => '', 'debit' => null, 'credit' => null, 'balance' => $ledger['opening_balance']]];
        foreach ($ledger['lines']->items() as $l) {
            $rows[] = ['date' => $l->date, 'entry' => $l->entry_number, 'description' => trim(($l->description ?: $l->entry_description).($l->reference ? ' · '.$l->reference : '')),
                'source' => $sources->describe($l->source_type, $l->source_id)['label'] ?? '', 'debit' => $l->debit ?: null, 'credit' => $l->credit ?: null, 'balance' => $l->balance];
        }
        $rows[] = ['date' => '', 'entry' => 'الإجمالي', 'description' => '', 'source' => '', 'debit' => $ledger['period_debit'], 'credit' => $ledger['period_credit'], 'balance' => $ledger['closing_balance']];

        return $this->table('دفتر الأستاذ: '.$account->code.' - '.$account->name_ar, $this->period($from, $to), [
            ['date', 'التاريخ'], ['entry', 'القيد'], ['description', 'البيان'], ['source', 'المستند المصدر'], ['debit', 'مدين', 'money'], ['credit', 'دائن', 'money'], ['balance', 'الرصيد', 'money'],
        ], $rows, [0, count($rows) - 1]);
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
            'subtitle' => $subtitle.' — أُنشئ '.now()->format('Y-m-d H:i'),
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? 'text'], $columns),
            'rows' => array_values($rows),
            'bold' => $bold,
        ];
    }
}

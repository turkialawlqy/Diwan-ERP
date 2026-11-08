<?php

namespace App\Services\Accounting;

use App\Models\ApprovalRequest;
use App\Models\AssetDepreciation;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\RecurringJournal;
use Illuminate\Support\Carbon;

/**
 * One list of what is still open before a fiscal year can be closed with confidence. Each item is ok, warn
 * (worth a look) or fail (blocks the closing or leaves the books wrong); the details point to the screen that fixes it.
 * It only reads: the figures come from the same services the individual screens use.
 */
class ClosingChecklist
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        protected YearEndClosing $closing,
        protected AccountingControls $controls,
        protected TaxSettlementService $tax,
        protected DepreciationService $depreciation,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, status: string, count: int, detail: string}>
     */
    public function forYear(FiscalYear $year, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $end = $year->end_date->copy()->startOfDay();
        $asOf = $end->lt($today) ? $end : $today;   // nothing after today can be pending yet
        $items = [];
        $add = function (string $key, string $label, int $count, string $failStatus, string $detail) use (&$items) {
            $items[] = ['key' => $key, 'label' => $label, 'status' => $count === 0 ? self::OK : $failStatus, 'count' => $count, 'detail' => $count === 0 ? 'لا شيء معلّق' : $detail];
        };

        $drafts = (int) collect($this->closing->branchStatus($year))->sum('drafts');
        $add('drafts', 'قيود مسودة داخل السنة', $drafts, self::FAIL, 'ترحيل المسودات أو حذفها قبل الإقفال — تقارير الرقابة ← الاستثناءات المحاسبية.');

        $openPeriods = FiscalPeriod::where('fiscal_year_id', $year->id)->where('status', 'open')->whereDate('end_date', '<', $today->toDateString())->count();
        $add('open_periods', 'فترات انتهت ولم تُقفل', $openPeriods, self::WARN, 'فترات شهرية مضى آخرها ما زالت مفتوحة — المحاسبة ← السنوات المالية.');

        $stale = collect($this->controls->cashMovement($year->start_date->copy(), $asOf)['unreconciled'])->filter(fn ($b) => ! str_starts_with($b['label'], '0-30'))->sum('count');
        $add('bank_unreconciled', 'حركات بنكية غير مسوّاة أقدم من 30 يومًا', (int) $stale, self::WARN, 'تسوية الحركات البنكية — المحاسبة ← التسويات البنكية، وتقرير حركة الصناديق والبنوك.');

        $add('depreciation', 'أصول لم يُرحَّل إهلاكها', $this->undepreciated($end, $today), self::FAIL, 'ترحيل الإهلاك الشهري — المحاسبة ← الأصول الثابتة.');

        $add('vat', 'ربع ضريبي بحركة ولم تُسوَّ ضريبته', $this->unsettledVatQuarters($year, $asOf), self::WARN, 'تسوية ضريبة القيمة المضافة — المحاسبة ← إقرار القيمة المضافة.');

        $reconciliation = $this->controls->partyReconciliation();
        $diffs = count($reconciliation['parties']) + collect($reconciliation['control'])->filter(fn ($c) => abs($c['difference']) > 0.009 || abs($c['untagged']) > 0.009)->count();
        $add('party_reconciliation', 'فروق مطابقة العملاء والموردين مع الدفتر', $diffs, self::WARN, 'أرصدة مخزّنة تختلف عن الدفتر أو قيود بلا طرف — تقارير الرقابة ← مطابقة الأستاذ المساعد.');

        $add('approvals', 'طلبات موافقة معلّقة', ApprovalRequest::where('status', 'pending')->count(), self::WARN, 'قرار المعتمد في طلبات الموافقة — تقارير الرقابة ← تدفق طلبات الموافقة.');

        $due = RecurringJournal::where('is_active', true)->whereDate('next_run_date', '<=', $asOf->toDateString())->count();
        $add('recurring', 'قيود متكررة مستحقة لم تُنشأ', $due, self::WARN, 'تشغيل القيود المتكررة المستحقة (أو مراجعة أخطائها) — المحاسبة ← القيود المتكررة.');

        return $items;
    }

    /** Active assets that still miss months of depreciation up to the month the year (or today) allows. */
    protected function undepreciated(Carbon $yearEnd, Carbon $today): int
    {
        // The scheduler posts the previous month on the 1st, so before the year ends only completed months count.
        $target = (int) ($yearEnd->lte($today) ? $yearEnd->copy()->endOfMonth() : $today->copy()->subMonthNoOverflow()->endOfMonth())->format('Ym');
        $last = AssetDepreciation::selectRaw('fixed_asset_id, MAX(period) as p')->groupBy('fixed_asset_id')->pluck('p', 'fixed_asset_id');

        return FixedAsset::where('status', 'active')->where('depreciation_method', '!=', 'units_of_production')->get()
            ->filter(fn (FixedAsset $a) => (int) $a->depreciation_start_date->format('Ym') <= $target && (int) ($last[$a->id] ?? 0) < $target && $this->depreciation->remaining($a) > 0.004)
            ->count();
    }

    /** Calendar quarters of the year (already over) with VAT activity in the ledger and no settlement covering them. */
    protected function unsettledVatQuarters(FiscalYear $year, Carbon $asOf): int
    {
        $count = 0;
        for ($q = $year->start_date->copy()->startOfQuarter(); $q->lte($year->end_date) && $q->copy()->endOfQuarter()->lte($asOf); $q->addQuarter()) {
            $from = $q->copy()->max($year->start_date);
            $to = $q->copy()->endOfQuarter();
            $preview = $this->tax->preview($from, $to);
            if ((abs($preview['output']) > 0.009 || abs($preview['input']) > 0.009) && ! $preview['settlement']) {
                $count++;
            }
        }

        return $count;
    }
}

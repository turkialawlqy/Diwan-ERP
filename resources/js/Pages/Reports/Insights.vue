<script setup>
import { cur } from '@/utils/tax';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { money } from '@/utils/format';

/**
 * Management dashboards: cash forecast, aging, profitability, budget.
 * Charts use palette slots 1 (blue) and 2 (orange), validated for CVD; every
 * chart also has its numbers in a table.
 */
const props = defineProps({ filters: Object, cash: Object, aging: Object, profitability: Object, budget: Object });

const tabs = [
    { id: 'cash', label: 'التدفق النقدي المتوقع' },
    { id: 'aging', label: 'أعمار الذمم' },
    { id: 'profit', label: 'الربحية' },
    { id: 'budget', label: 'الموازنة مقابل الفعلي' },
];
const tab = ref('cash');
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const applyPeriod = () => router.get(route('reports.insights'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });

const BLUE = '#2a78d6';
const ORANGE = '#eb6834';
const weekLabel = (w) => w.start.substring(5).replace('-', '/');
const labels = computed(() => props.cash.weeks.map(weekLabel));

const baseOptions = {
    chart: { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', animations: { enabled: false } },
    dataLabels: { enabled: false },
    grid: { borderColor: '#eef0f3', strokeDashArray: 0, yaxis: { lines: { show: true } }, xaxis: { lines: { show: false } } },
    xaxis: { categories: [], labels: { rotate: -45, rotateAlways: true, hideOverlappingLabels: true, style: { colors: '#64748b', fontSize: '10px' } }, axisBorder: { color: '#e2e8f0' }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: '#64748b', fontSize: '11px' }, formatter: (v) => Math.round(v).toLocaleString('en-US') } },
    tooltip: { shared: true, intersect: false, y: { formatter: (v) => `${money(v)} ${cur()}` } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px', markers: { size: 6 } },
};

const balanceChart = computed(() => ({
    series: [{ name: 'الرصيد النقدي المتوقع', data: props.cash.weeks.map((w) => w.balance) }],
    options: { ...baseOptions, colors: [BLUE], stroke: { width: 2, curve: 'straight' }, markers: { size: 0, hover: { size: 6 } }, legend: { show: false },
        xaxis: { ...baseOptions.xaxis, categories: labels.value }, annotations: { yaxis: [{ y: 0, borderColor: '#94a3b8' }] } },
}));

const flowChart = computed(() => ({
    series: [
        { name: 'المقبوضات', data: props.cash.weeks.map((w) => w.inflow) },
        { name: 'المدفوعات', data: props.cash.weeks.map((w) => w.outflow) },
    ],
    options: { ...baseOptions, colors: [BLUE, ORANGE], plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
        stroke: { show: true, width: 2, colors: ['transparent'] }, xaxis: { ...baseOptions.xaxis, categories: labels.value } },
}));

const buckets = [['current', 'غير مستحقة'], ['d30', '1–30 يوم'], ['d60', '31–60'], ['d90', '61–90'], ['d90plus', 'أكثر من 90']];
const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);
const varianceClass = (v) => (v < 0 ? 'text-rose-700' : 'text-emerald-700');
</script>

<template>
    <AppLayout>
        <Head title="لوحات القيادة والتحليلات" />
        <PageHeader title="لوحات القيادة والتحليلات" subtitle="كل المبالغ بالعملة الأساسية للمنشأة" :crumbs="[{ label: 'التقارير' }, { label: 'لوحات القيادة' }]" />

        <div class="flex flex-wrap items-end justify-between gap-3 mb-4 text-xs">
            <div class="flex flex-wrap gap-1 bg-white border border-slate-200 rounded-xl p-1">
                <button v-for="t in tabs" :key="t.id" type="button" @click="tab = t.id" :class="['px-3 py-2 rounded-lg font-bold', tab === t.id ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50']">{{ t.label }}</button>
            </div>
            <div v-if="tab === 'profit'" class="flex items-end gap-2">
                <label class="block">من<input v-model="from" type="date" class="block rounded-xl border-slate-200 text-xs" /></label>
                <label class="block">إلى<input v-model="to" type="date" class="block rounded-xl border-slate-200 text-xs" /></label>
                <button @click="applyPeriod" class="px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">تطبيق</button>
            </div>
        </div>

        <!-- Cash forecast -->
        <section v-show="tab === 'cash'" class="space-y-4 text-xs">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-slate-500">النقدية اليوم</div><div class="text-xl font-black mt-1 font-mono">{{ money(cash.opening) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-slate-500">المتوقع بعد {{ cash.weeks.length }} أسبوعاً</div><div class="text-xl font-black mt-1 font-mono">{{ money(cash.closing) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <div class="text-slate-500">أدنى رصيد متوقع</div>
                    <div class="text-xl font-black mt-1 font-mono" :class="cash.lowest < 0 ? 'text-rose-700' : ''">{{ money(cash.lowest) }}</div>
                    <div v-if="cash.lowest < 0" class="text-rose-700 font-bold mt-1">⚠ عجز متوقع — راجع التحصيل أو المدفوعات</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <div class="text-slate-500">ذمم متأخرة (خارج التوقع)</div>
                    <div class="text-sm font-bold mt-1">عملاء: <span class="font-mono">{{ money(cash.overdue.receivables) }}</span></div>
                    <div class="text-sm font-bold">موردون: <span class="font-mono">{{ money(cash.overdue.payables) }}</span></div>
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <h3 class="font-black text-slate-700 mb-1">الرصيد النقدي المتوقع أسبوعياً</h3>
                    <apexchart type="line" height="260" :options="balanceChart.options" :series="balanceChart.series" />
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <h3 class="font-black text-slate-700 mb-1">المقبوضات والمدفوعات المتوقعة</h3>
                    <apexchart type="bar" height="260" :options="flowChart.options" :series="flowChart.series" />
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الأسبوع</th><th class="px-3 py-2">تحصيل فواتير</th><th class="px-3 py-2">فواتير دورية</th><th class="px-3 py-2">شيكات واردة</th><th class="px-3 py-2">موردون</th><th class="px-3 py-2">شيكات صادرة</th><th class="px-3 py-2">رواتب</th><th class="px-3 py-2">الصافي</th><th class="px-3 py-2">الرصيد</th></tr></thead>
                    <tbody>
                        <tr v-for="w in cash.weeks" :key="w.start" class="border-t border-slate-100 font-mono text-center">
                            <td class="px-3 py-2 text-start">{{ w.start }} — {{ w.end.substring(5) }}</td>
                            <td class="px-3 py-2">{{ money(w.receivables) }}</td><td class="px-3 py-2">{{ money(w.recurring) }}</td><td class="px-3 py-2">{{ money(w.cheques_in) }}</td>
                            <td class="px-3 py-2">{{ money(w.payables) }}</td><td class="px-3 py-2">{{ money(w.cheques_out) }}</td><td class="px-3 py-2">{{ money(w.payroll) }}</td>
                            <td class="px-3 py-2" :class="w.net < 0 ? 'text-rose-700' : ''">{{ money(w.net) }}</td>
                            <td class="px-3 py-2 font-bold" :class="w.balance < 0 ? 'text-rose-700' : ''">{{ money(w.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-slate-500">الرواتب مقدّرة من آخر مسير معتمد{{ cash.payroll_estimate ? ` (${money(cash.payroll_estimate)})` : '' }}، والفواتير الدورية من قوالبها النشطة. الذمم المتأخرة لا تُفترض محصّلة.</p>
        </section>

        <!-- Aging -->
        <section v-show="tab === 'aging'" class="grid lg:grid-cols-2 gap-4 text-xs">
            <div v-for="(label, kind) in { receivables: 'أعمار ذمم العملاء', payables: 'أعمار مستحقات الموردين' }" :key="kind" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ label }}</h3>
                <table class="w-full">
                    <tbody>
                        <tr v-for="[key, name] in buckets" :key="key" class="border-t border-slate-100">
                            <td class="px-4 py-2">{{ name }}</td>
                            <td class="px-4 py-2 w-1/2">
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full" :style="{ width: (aging[kind].totals.total ? (aging[kind].totals[key] / aging[kind].totals.total * 100) : 0) + '%', background: key === 'current' ? BLUE : ORANGE }"></div></div>
                            </td>
                            <td class="px-4 py-2 font-mono text-end">{{ money(aging[kind].totals[key]) }}</td>
                        </tr>
                        <tr class="border-t border-slate-200 font-black"><td class="px-4 py-2">الإجمالي</td><td></td><td class="px-4 py-2 font-mono text-end">{{ money(aging[kind].totals.total) }}</td></tr>
                    </tbody>
                </table>
                <h4 class="px-4 pt-3 pb-1 font-bold text-slate-600">الأكثر تأخراً</h4>
                <div class="overflow-x-auto"><table class="w-full">
                    <tbody>
                        <tr v-for="r in aging[kind].top" :key="r.id ?? `none`" class="border-t border-slate-100">
                            <td class="px-4 py-2 font-bold">{{ r.name }}</td>
                            <td class="px-4 py-2 font-mono text-rose-700">{{ money(r.overdue) }}</td>
                            <td class="px-4 py-2 text-end"><ShareDocument v-if="kind === 'receivables' && r.id && $can('sales.clients.view')" type="statement" :id="r.id" /></td>
                        </tr>
                        <tr v-if="!aging[kind].top.length"><td class="px-4 py-4 text-center text-slate-400">لا توجد مبالغ متأخرة</td></tr>
                    </tbody>
                </table></div>
            </div>
        </section>

        <!-- Profitability -->
        <section v-show="tab === 'profit'" class="space-y-4 text-xs">
            <div v-for="(block, key) in { products: 'ربحية الأصناف', customers: 'ربحية العملاء' }" :key="key" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ block }} <span class="font-normal text-slate-400">({{ filters.from }} — {{ filters.to }})</span></h3>
                <table class="w-full min-w-[640px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ key === 'products' ? 'الصنف' : 'العميل' }}</th><th class="px-3 py-2">{{ key === 'products' ? 'الكمية' : 'الفواتير' }}</th><th class="px-3 py-2">المبيعات (قبل الضريبة)</th><th class="px-3 py-2">التكلفة</th><th class="px-3 py-2">مجمل الربح</th><th class="px-3 py-2">الهامش</th></tr></thead>
                    <tbody>
                        <tr v-for="r in profitability[key]" :key="r.id" class="border-t border-slate-100 text-center font-mono">
                            <td class="px-3 py-2 text-start font-sans font-bold">{{ r.name }}</td>
                            <td class="px-3 py-2">{{ key === 'products' ? r.qty : r.invoices }}</td>
                            <td class="px-3 py-2">{{ money(r.revenue) }}</td><td class="px-3 py-2">{{ money(r.cost) }}</td>
                            <td class="px-3 py-2" :class="r.margin < 0 ? 'text-rose-700' : ''">{{ money(r.margin) }}</td>
                            <td class="px-3 py-2">{{ pct(r.margin_pct) }}</td>
                        </tr>
                        <tr v-if="!profitability[key].length"><td colspan="6" class="px-3 py-6 text-center text-slate-400">لا توجد مبيعات في الفترة</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <h3 class="px-4 py-3 font-black border-b border-slate-100">ربحية المشاريع الجارية</h3>
                <table class="w-full min-w-[760px]">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">المشروع</th><th class="px-3 py-2">قيمة العقد</th><th class="px-3 py-2">الإنجاز</th><th class="px-3 py-2">الإيراد</th><th class="px-3 py-2">التكلفة الفعلية</th><th class="px-3 py-2">مجمل الربح</th><th class="px-3 py-2">الهامش الفعلي</th><th class="px-3 py-2">الهامش المخطط</th></tr></thead>
                    <tbody>
                        <tr v-for="p in profitability.projects" :key="p.id" class="border-t border-slate-100 text-center font-mono">
                            <td class="px-3 py-2 text-start font-sans"><Link :href="route('projects.show', p.id)" class="font-bold text-indigo-700">{{ p.code }} {{ p.name }}</Link></td>
                            <td class="px-3 py-2">{{ money(p.contract_value) }}</td><td class="px-3 py-2">{{ pct(p.progress_percent) }}</td>
                            <td class="px-3 py-2">{{ money(p.revenue) }}</td><td class="px-3 py-2">{{ money(p.actual_cost) }}</td>
                            <td class="px-3 py-2" :class="p.gross_profit < 0 ? 'text-rose-700' : ''">{{ money(p.gross_profit) }}</td>
                            <td class="px-3 py-2">{{ pct(p.margin_percent) }}</td><td class="px-3 py-2">{{ pct(p.projected_margin) }}</td>
                        </tr>
                        <tr v-if="!profitability.projects.length"><td colspan="8" class="px-3 py-6 text-center text-slate-400">لا توجد مشاريع جارية</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Budget -->
        <section v-show="tab === 'budget'" class="space-y-4 text-xs">
            <div v-if="!budget" class="bg-white border border-dashed border-slate-300 rounded-2xl p-10 text-center text-slate-500">
                لا توجد موازنة معتمدة للسنة المالية الحالية. أنشئها واعتمدها من المحاسبة ← الموازنات.
            </div>
            <template v-else>
                <div class="grid sm:grid-cols-2 gap-3">
                    <div v-for="(label, k) in { revenue: 'الإيرادات حتى تاريخه', expense: 'المصروفات حتى تاريخه' }" :key="k" class="bg-white border border-slate-200 rounded-2xl p-4">
                        <div class="text-slate-500">{{ label }} — {{ budget.budget.name }}</div>
                        <div class="text-xl font-black mt-1 font-mono">{{ money(budget.totals[k].actual) }} <span class="text-sm text-slate-400">/ {{ money(budget.totals[k].planned) }}</span></div>
                        <div class="h-2 mt-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full" :style="{ width: Math.min(100, budget.totals[k].planned ? budget.totals[k].actual / budget.totals[k].planned * 100 : 0) + '%', background: BLUE }"></div></div>
                    </div>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                    <h3 class="px-4 py-3 font-black border-b border-slate-100">أكبر الانحرافات غير المواتية</h3>
                    <table class="w-full min-w-[600px]">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الحساب</th><th class="px-3 py-2">المخطط</th><th class="px-3 py-2">الفعلي</th><th class="px-3 py-2">الانحراف</th><th class="px-3 py-2">نسبة الاستخدام</th></tr></thead>
                        <tbody>
                            <tr v-for="r in budget.worst" :key="r.code" class="border-t border-slate-100 text-center font-mono">
                                <td class="px-3 py-2 text-start font-sans">{{ r.code }} — {{ r.name }}</td>
                                <td class="px-3 py-2">{{ money(r.planned) }}</td><td class="px-3 py-2">{{ money(r.actual) }}</td>
                                <td class="px-3 py-2 font-bold" :class="varianceClass(r.variance)">{{ money(r.variance) }}</td><td class="px-3 py-2">{{ pct(r.used_pct) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>
    </AppLayout>
</template>

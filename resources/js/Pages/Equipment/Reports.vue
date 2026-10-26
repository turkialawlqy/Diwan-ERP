<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money, qty } from '@/utils/format';

const props = defineProps({ type: String, filters: Object, report: Object, columns: Object });
const types = {
    profitability: ['ربحية المعدات', 'الإيراد والتحميل على المشاريع مقابل الصيانة والإيجار والإهلاك'],
    utilization: ['نسبة التشغيل', 'ساعات العمل الفعلية مقابل المتاحة، وأيام التوقف والصيانة'],
    compliance: ['الالتزام بالصيانة', 'البنود المنفذة في موعدها مقابل المتأخرة، وتكلفة الصيانة لكل ساعة وكيلومتر'],
    parts: ['استهلاك قطع الغيار', 'الأصناف المصروفة للصيانة حسب الصنف والمعدة'],
};
const f = reactive({ ...props.filters });
const go = (type = props.type) => router.get(route('equipment.reports.index'), { type, ...f }, { preserveState: false });
const exportUrl = computed(() => route('equipment.reports.export', { type: props.type, ...f }));

const moneyKeys = ['revenue', 'recovery', 'income', 'maintenance', 'rent_in', 'depreciation', 'costs', 'net', 'cost_per_hour', 'parts_cost', 'amount'];
const percentKeys = ['margin', 'utilization', 'on_time_rate'];
const cell = (key, value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (moneyKeys.includes(key)) return money(value);
    if (percentKeys.includes(key)) return `${value}%`;
    if (typeof value === 'number') return qty(value);
    return value;
};
const tone = (key, row) => {
    if (key === 'net' || key === 'margin') return Number(row[key]) < 0 ? 'text-rose-600 font-bold' : 'text-emerald-700 font-bold';
    if (key === 'late' || key === 'overdue_now') return Number(row[key]) > 0 ? 'text-rose-600 font-bold' : '';
    if (key === 'utilization') return Number(row[key]) < 40 ? 'text-amber-700 font-bold' : 'text-emerald-700 font-bold';
    return '';
};
const linkable = (key) => ['code', 'name', 'equipment'].includes(key);
const rowId = (row) => row.equipment_id || row.id;

// Short management readout above the table.
const insights = computed(() => {
    const rows = props.report.rows;
    if (!rows.length) return [];
    if (props.type === 'profitability') {
        const losing = rows.filter((r) => r.net < 0);
        const best = [...rows].sort((a, b) => b.net - a.net)[0];
        return [`صافي ربح الأسطول: ${money(props.report.totals.net)}`, best && `الأعلى ربحاً: ${best.code} — ${best.name} (${money(best.net)})`,
            losing.length && `معدات خاسرة: ${losing.map((r) => r.code).join('، ')} — راجع أسعارها أو تكاليف صيانتها`].filter(Boolean);
    }
    if (props.type === 'utilization') {
        const t = props.report.totals;
        const idle = rows.filter((r) => r.utilization < 40);
        return [`متوسط التشغيل: ${t.available_hours ? ((t.worked_hours / t.available_hours) * 100).toFixed(1) : 0}% (${props.report.standard_hours} ساعات/يوم، ${props.report.days} يوماً)`,
            idle.length && `معدات تشغيلها أقل من 40%: ${idle.map((r) => r.code).join('، ')} — فرصة للتأجير أو البيع`].filter(Boolean);
    }
    if (props.type === 'compliance') {
        const t = props.report.totals;
        return [`نُفذ ${t.serviced} بند، منها ${t.late} بعد موعدها (${t.serviced ? Math.round(((t.serviced - t.late) / t.serviced) * 100) : 100}% التزام)`,
            t.overdue_now && `${t.overdue_now} بند متأخر الآن`, `أوامر إصلاح أعطال: ${t.corrective} مقابل ${t.preventive} صيانة وقائية`].filter(Boolean);
    }
    const top = props.report.by_product?.[0];
    return [`إجمالي قطع الغيار: ${money(props.report.totals.amount)}`, top && `الأعلى تكلفة: ${top.product} (${money(top.amount)})`].filter(Boolean);
});
</script>

<template>
    <AppLayout>
        <Head title="تقارير المعدات" />
        <PageHeader title="تقارير المعدات" :subtitle="types[type][1]" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: 'التقارير' }]">
            <template #actions>
                <a v-if="$can('equipment.reports.export')" :href="exportUrl" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">تصدير Excel (CSV)</a>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-1 border-b border-slate-200 mb-4 text-xs" role="tablist">
            <button v-for="(t, k) in types" :key="k" role="tab" :aria-selected="type === k" @click="go(k)" :class="['px-4 py-2 font-bold border-b-2 -mb-px', type === k ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500']">{{ t[0] }}</button>
        </div>

        <form @submit.prevent="go()" class="flex flex-wrap items-end gap-3 mb-4 text-xs">
            <label class="font-bold text-slate-600">من <input v-model="f.from" type="date" class="block rounded-xl border-slate-200 text-xs" /></label>
            <label class="font-bold text-slate-600">إلى <input v-model="f.to" type="date" class="block rounded-xl border-slate-200 text-xs" /></label>
            <label v-if="type === 'utilization'" class="font-bold text-slate-600">ساعات اليوم القياسية <input v-model="f.standard_hours" type="number" min="1" max="24" class="block rounded-xl border-slate-200 text-xs w-24" /></label>
            <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">عرض</button>
        </form>

        <ul v-if="insights.length" class="mb-4 rounded-2xl bg-indigo-50 border border-indigo-100 p-4 text-xs text-indigo-900 space-y-1 list-disc ps-8">
            <li v-for="(i, n) in insights" :key="n">{{ i }}</li>
        </ul>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th v-for="(l, k) in columns" :key="k" class="px-3 py-2 text-start whitespace-nowrap">{{ l }}</th></tr></thead>
                <tbody>
                    <tr v-for="(row, n) in report.rows" :key="n" class="border-t border-slate-100">
                        <td v-for="(l, k) in columns" :key="k" :class="['px-3 py-2 whitespace-nowrap', typeof row[k] === 'number' ? 'font-mono' : '', tone(k, row)]">
                            <Link v-if="linkable(k) && rowId(row)" :href="route('equipment.show', rowId(row))" class="hover:text-indigo-700">{{ cell(k, row[k]) }}</Link>
                            <template v-else-if="k === 'utilization'">
                                <div class="flex items-center gap-2"><div class="w-24 h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full bg-indigo-500" :style="{ width: `${Math.min(100, row[k])}%` }"></div></div>{{ cell(k, row[k]) }}</div>
                            </template>
                            <template v-else>{{ cell(k, row[k]) }}</template>
                        </td>
                    </tr>
                    <tr v-if="!report.rows.length"><td :colspan="Object.keys(columns).length" class="px-3 py-10 text-center text-slate-400">لا توجد بيانات في هذه الفترة.</td></tr>
                </tbody>
                <tfoot v-if="report.rows.length && report.totals" class="bg-slate-50 font-bold">
                    <tr><td v-for="(l, k) in columns" :key="k" class="px-3 py-2 font-mono whitespace-nowrap">{{ k in report.totals ? cell(k, report.totals[k]) : (k === Object.keys(columns)[0] ? 'الإجمالي' : '') }}</td></tr>
                </tfoot>
            </table>
        </div>

        <section v-if="type === 'parts' && report.by_product?.length" class="mt-4 text-xs">
            <h3 class="font-black text-slate-700 mb-2">حسب الصنف</h3>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الصنف</th><th class="px-3 py-2 text-start">الكمية</th><th class="px-3 py-2 text-start">التكلفة</th><th class="px-3 py-2 text-start">عدد المعدات</th></tr></thead>
                    <tbody><tr v-for="p in report.by_product" :key="p.product" class="border-t border-slate-100"><td class="px-3 py-2">{{ p.product }} <span class="text-slate-400 font-mono">{{ p.sku }}</span></td><td class="px-3 py-2 font-mono">{{ qty(p.quantity) }}</td><td class="px-3 py-2 font-mono">{{ money(p.amount) }}</td><td class="px-3 py-2">{{ p.equipment_count }}</td></tr></tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>

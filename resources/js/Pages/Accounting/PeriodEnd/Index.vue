<script setup>
import { reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { money } from '@/utils/format';

const props = defineProps({ fx: Object, zakat: Object, years: Array, filters: Object });

const filters = reactive({ ...props.filters });
const reload = () => router.get(route('accounting.period-end.index'), filters, { preserveState: true, preserveScroll: true });

const fxForm = useForm({ month: props.filters.month });
const revalue = () => {
    if (confirm(`ترحيل قيد إعادة تقييم العملات لشهر ${filters.month} وعكسه تلقائياً في أول الشهر التالي؟`)) {
        fxForm.month = filters.month;
        fxForm.post(route('accounting.period-end.revalue'), { preserveScroll: true });
    }
};

const zakatForm = useForm({});
const postZakat = () => {
    if (confirm(`ترحيل مخصص الزكاة بمبلغ ${money(props.zakat.amount)}؟`)) {
        zakatForm.transform(() => ({ fiscal_year_id: filters.fiscal_year_id, calendar: filters.calendar, saudi_share: filters.saudi_share, additions: filters.additions, deductions: filters.deductions }))
            .post(route('accounting.period-end.zakat'), { preserveScroll: true });
    }
};

const zakatRows = [
    ['equity', 'حقوق الملكية (بداية السنة + حركتها)', 1],
    ['adjusted_profit', 'صافي الربح المعدل (بعد إضافة مصروف الزكاة)', 1],
    ['long_term_liabilities', 'الخصوم غير المتداولة (مخصصات وقروض طويلة الأجل)', 1],
    ['additions', 'إضافات أخرى', 1],
    ['fixed_assets', 'صافي الأصول الثابتة', -1],
    ['deductions', 'حسميات أخرى (استثمارات طويلة الأجل...)', -1],
];
</script>

<template>
    <AppLayout>
        <Head title="نهاية الفترة" />
        <PageHeader title="نهاية الفترة" subtitle="إعادة تقييم العملات الأجنبية شهرياً، ومخصص الزكاة سنوياً" />

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <!-- FX revaluation -->
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-black text-slate-800">إعادة تقييم أرصدة العملات الأجنبية</h2>
                        <p class="text-3xs text-slate-500 mt-1">الفواتير المفتوحة بعملة أجنبية تُقاس بسعر نهاية الشهر؛ يُعكس القيد أول الشهر التالي حتى لا يتكرر الفرق عند السداد.</p>
                    </div>
                    <Field label="الشهر"><input v-model="filters.month" type="month" @change="reload" class="rounded-xl border-slate-200 text-xs" /></Field>
                </div>

                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr><th class="p-2 text-start">المستند</th><th class="p-2 text-start">الطرف</th><th class="p-2">المتبقي</th><th class="p-2">سعر القيد</th><th class="p-2">سعر الإقفال</th><th class="p-2 text-end">الفرق</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in fx.rows" :key="r.kind + r.number">
                            <td class="p-2 font-mono">{{ r.number }} <span class="text-3xs text-slate-400">{{ r.kind === 'receivable' ? 'مبيعات' : 'مشتريات' }}</span></td>
                            <td class="p-2">{{ r.party }}</td>
                            <td class="p-2 text-center font-mono">{{ money(r.remaining) }} {{ r.currency }}</td>
                            <td class="p-2 text-center font-mono">{{ r.book_rate }}</td>
                            <td class="p-2 text-center font-mono">{{ r.closing_rate }}</td>
                            <td class="p-2 text-end font-mono font-bold">{{ money(r.difference) }}</td>
                        </tr>
                        <tr v-if="!fx.rows.length"><td colspan="6" class="p-6 text-center text-slate-400">لا توجد أرصدة مفتوحة بعملات أجنبية في هذا الشهر.</td></tr>
                    </tbody>
                </table></div>

                <div class="flex items-center justify-between">
                    <p class="text-xs">صافي الأثر: <strong :class="fx.net_gain >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(fx.net_gain) }}</strong> {{ fx.net_gain >= 0 ? 'ربح' : 'خسارة' }} غير محقق</p>
                    <span v-if="fx.posted" class="text-3xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-1">مُرحّل لهذا الشهر</span>
                    <button v-else-if="$can('accounting.period_end.post') && fx.rows.length" @click="revalue" :disabled="fxForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">ترحيل إعادة التقييم</button>
                </div>
                <p v-if="fxForm.errors.month" class="text-xs text-rose-600">{{ fxForm.errors.month }}</p>
            </section>

            <!-- Zakat -->
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
                <div>
                    <h2 class="font-black text-slate-800">مخصص الزكاة الشرعية</h2>
                    <p class="text-3xs text-slate-500 mt-1">طريقة صافي الأصول: الوعاء لا يقل عن صافي الربح المعدل. راجع الحساب مع مستشارك قبل تقديم الإقرار لهيئة الزكاة والضريبة والجمارك.</p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <Field label="السنة المالية">
                        <select v-model="filters.fiscal_year_id" @change="reload" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
                    </Field>
                    <Field label="أساس السنة">
                        <select v-model="filters.calendar" @change="reload" class="w-full rounded-xl border-slate-200 text-xs"><option value="gregorian">ميلادية (2.5776%)</option><option value="hijri">هجرية (2.5%)</option></select>
                    </Field>
                    <Field label="حصة الملكية السعودية/الخليجية %"><input v-model.number="filters.saudi_share" type="number" min="0" max="100" @change="reload" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="إضافات أخرى"><input v-model.number="filters.additions" type="number" min="0" step="0.01" @change="reload" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="حسميات أخرى"><input v-model.number="filters.deductions" type="number" min="0" step="0.01" @change="reload" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>

                <div class="overflow-x-auto" v-if="zakat"><table class="w-full text-xs">
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="[key, label, sign] in zakatRows" :key="key">
                            <td class="p-2">{{ sign > 0 ? '+' : '−' }} {{ label }}</td>
                            <td class="p-2 text-end font-mono">{{ money(zakat[key]) }}</td>
                        </tr>
                        <tr class="font-bold bg-slate-50"><td class="p-2">الوعاء المحسوب</td><td class="p-2 text-end font-mono">{{ money(zakat.computed_base) }}</td></tr>
                        <tr class="font-bold"><td class="p-2">الوعاء الزكوي المعتمد (لا يقل عن الربح المعدل)</td><td class="p-2 text-end font-mono">{{ money(zakat.base) }}</td></tr>
                        <tr class="font-black text-indigo-700 bg-indigo-50"><td class="p-2">الزكاة المستحقة ({{ zakat.rate }}% × {{ zakat.saudi_share }}%)</td><td class="p-2 text-end font-mono">{{ money(zakat.amount) }}</td></tr>
                    </tbody>
                </table></div>

                <div v-if="zakat" class="flex items-center justify-end gap-3">
                    <span v-if="zakat.assessment" class="text-3xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-1">سُجّل المخصص: {{ money(zakat.assessment.amount) }}</span>
                    <button v-else-if="$can('accounting.period_end.post') && zakat.amount > 0" @click="postZakat" :disabled="zakatForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">ترحيل مخصص الزكاة</button>
                </div>
                <p v-for="(msg, key) in zakatForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            </section>
        </div>
    </AppLayout>
</template>

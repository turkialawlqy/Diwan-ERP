<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { reactive, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { money } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ fx: Object, zakat: Object, years: Array, filters: Object });

const filters = reactive({ ...props.filters });
const reload = () => router.get(route('accounting.period-end.index'), filters, { preserveState: true, preserveScroll: true });

const fxForm = useForm({ month: props.filters.month });
const revalue = () => {
    if (confirm(t('a_period_end.confirm_reval', { month: filters.month }))) {
        fxForm.month = filters.month;
        fxForm.post(route('accounting.period-end.revalue'), { preserveScroll: true });
    }
};

const zakatForm = useForm({});
const postZakat = () => {
    if (confirm(t('a_period_end.confirm_zakat', { amount: money(props.zakat.amount) }))) {
        zakatForm.transform(() => ({ fiscal_year_id: filters.fiscal_year_id, calendar: filters.calendar, saudi_share: filters.saudi_share, additions: filters.additions, deductions: filters.deductions }))
            .post(route('accounting.period-end.zakat'), { preserveScroll: true });
    }
};

const zakatRows = [
    ['equity', t('a_period_end.z_equity'), 1],
    ['adjusted_profit', t('a_period_end.z_profit'), 1],
    ['long_term_liabilities', t('a_period_end.z_lt'), 1],
    ['additions', t('a_period_end.other_additions'), 1],
    ['fixed_assets', t('a_period_end.z_fa'), -1],
    ['deductions', t('a_period_end.z_ded'), -1],
];
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_period_end.period_end')" />
        <PageHeader :title="$t('a_period_end.period_end')" :subtitle="$t('a_period_end.foreign_currency_revaluation_mon')" />

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <!-- FX revaluation -->
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-black text-slate-800">{{ $t('a_period_end.revaluation_of_foreign_currency') }}</h2>
                        <p class="text-3xs text-slate-500 mt-1">{{ $t('a_period_end.open_invoices_in_a_foreign_curre') }}</p>
                    </div>
                    <Field :label="$t('a_period_end.month')"><DatePicker v-model="filters.month" type="month" @change="reload" class="rounded-xl border-slate-200 text-xs" /></Field>
                </div>

                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr><th class="p-2 text-start">{{ $t('a_period_end.document') }}</th><th class="p-2 text-start">{{ $t('a_period_end.party') }}</th><th class="p-2">{{ $t('a_period_end.remaining') }}</th><th class="p-2">{{ $t('a_period_end.booked_rate') }}</th><th class="p-2">{{ $t('a_period_end.closing_rate') }}</th><th class="p-2 text-end">{{ $t('a_period_end.difference') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in fx.rows" :key="r.kind + r.number">
                            <td class="p-2 font-mono">{{ r.number }} <span class="text-3xs text-slate-400">{{ r.kind === 'receivable' ? $t('a_period_end.sales') : $t('a_period_end.purchases') }}</span></td>
                            <td class="p-2">{{ r.party }}</td>
                            <td class="p-2 text-center font-mono">{{ money(r.remaining) }} {{ r.currency }}</td>
                            <td class="p-2 text-center font-mono">{{ r.book_rate }}</td>
                            <td class="p-2 text-center font-mono">{{ r.closing_rate }}</td>
                            <td class="p-2 text-end font-mono font-bold">{{ money(r.difference) }}</td>
                        </tr>
                        <tr v-if="!fx.rows.length"><td colspan="6" class="p-6 text-center text-slate-400">{{ $t('a_period_end.no_open_foreign_currency_balance') }}</td></tr>
                    </tbody>
                </table></div>

                <div class="flex items-center justify-between">
                    <p class="text-xs">{{ $t('a_period_end.net_effect') }} <strong :class="fx.net_gain >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(fx.net_gain) }}</strong> {{ fx.net_gain >= 0 ? 'ربح' : 'خسارة' }} غير محقق</p>
                    <span v-if="fx.posted" class="text-3xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-1">{{ $t('a_period_end.posted_for_this_month') }}</span>
                    <button v-else-if="$can('accounting.period_end.post') && fx.rows.length" @click="revalue" :disabled="fxForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('a_period_end.post_the_revaluation') }}</button>
                </div>
                <p v-if="fxForm.errors.month" class="text-xs text-rose-600">{{ fxForm.errors.month }}</p>
            </section>

            <!-- Zakat (Saudi branches) -->
            <section v-if="$feature('zakat')" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
                <div>
                    <h2 class="font-black text-slate-800">{{ $t('a_period_end.zakat_provision') }}</h2>
                    <p class="text-3xs text-slate-500 mt-1">{{ $t('a_period_end.net_assets_method_the_base_is_no') }}</p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <Field :label="$t('a_period_end.fiscal_year')">
                        <select v-model="filters.fiscal_year_id" @change="reload" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
                    </Field>
                    <Field :label="$t('a_period_end.year_basis')">
                        <select v-model="filters.calendar" @change="reload" class="w-full rounded-xl border-slate-200 text-xs"><option value="gregorian">{{ $t('a_period_end.gregorian_2_5776') }}</option><option value="hijri">{{ $t('a_period_end.hijri_2_5') }}</option></select>
                    </Field>
                    <Field :label="$t('a_period_end.saudi_gcc_ownership_share')"><input v-model.number="filters.saudi_share" type="text" inputmode="decimal" data-num min="0" max="100" @change="reload" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('a_period_end.other_additions')"><input v-model.number="filters.additions" type="text" inputmode="decimal" data-num min="0" step="0.01" @change="reload" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('a_period_end.other_deductions')"><input v-model.number="filters.deductions" type="text" inputmode="decimal" data-num min="0" step="0.01" @change="reload" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>

                <div class="overflow-x-auto" v-if="zakat"><table class="w-full text-xs">
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="[key, label, sign] in zakatRows" :key="key">
                            <td class="p-2">{{ sign > 0 ? '+' : '−' }} {{ label }}</td>
                            <td class="p-2 text-end font-mono">{{ money(zakat[key]) }}</td>
                        </tr>
                        <tr class="font-bold bg-slate-50"><td class="p-2">{{ $t('a_period_end.calculated_base') }}</td><td class="p-2 text-end font-mono">{{ money(zakat.computed_base) }}</td></tr>
                        <tr class="font-bold"><td class="p-2">{{ $t('a_period_end.approved_zakat_base_not_less_tha') }}</td><td class="p-2 text-end font-mono">{{ money(zakat.base) }}</td></tr>
                        <tr class="font-black text-indigo-700 bg-indigo-50"><td class="p-2">{{ $t('a_period_end.zakat_due') }} ({{ zakat.rate }}% × {{ zakat.saudi_share }}%)</td><td class="p-2 text-end font-mono">{{ money(zakat.amount) }}</td></tr>
                    </tbody>
                </table></div>

                <div v-if="zakat" class="flex items-center justify-end gap-3">
                    <span v-if="zakat.assessment" class="text-3xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-1">{{ $t('a_period_end.provision_recorded') }}: {{ money(zakat.assessment.amount) }}</span>
                    <button v-else-if="$can('accounting.period_end.post') && zakat.amount > 0" @click="postZakat" :disabled="zakatForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('a_period_end.post_the_zakat_provision') }}</button>
                </div>
                <p v-for="(msg, key) in zakatForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            </section>
        </div>
    </AppLayout>
</template>

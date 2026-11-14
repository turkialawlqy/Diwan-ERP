<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, monthLabel, today, thisMonth } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ asset: Object, monthlyAmount: Number, schedule: Array, bankAccounts: Array });
const methods = computed(() => ({ straight_line: t('a_asset.m_sl'), declining_balance: t('a_asset.m_db'), units_of_production: t('a_asset.m_up') }));
const units = props.asset.depreciation_method === 'units_of_production';
const impairOpen = ref(false);
const showAll = ref(false);
const impairForm = useForm({ date: today(), amount: '', reason: '' });
const impair = () => impairForm.post(route('accounting.fixed-assets.impair', props.asset.id), { preserveScroll: true, onSuccess: () => { impairOpen.value = false; impairForm.reset(); } });
const usageForm = useForm({ month: thisMonth(), units: '' });
const saveUsage = () => usageForm.post(route('accounting.fixed-assets.usage', props.asset.id), { preserveScroll: true, onSuccess: () => usageForm.reset('units') });
const usageOf = (period) => props.asset.usages?.find((u) => Number(u.period) === Number(period))?.units;
const disposeOpen = ref(false);
const form = useForm({ date: today(), proceeds: 0, method: 'bank_transfer', bank_account_id: '', taxable: false });
const dispose = () => form.transform((d) => ({ ...d, vat_amount: d.taxable ? Math.round((parseFloat(d.proceeds) || 0) * 15) / 100 : 0 })).post(route('accounting.fixed-assets.dispose', props.asset.id), { onSuccess: () => (disposeOpen.value = false) });
</script>

<template>
    <AppLayout>
        <Head :title="asset.name_ar" />
        <PageHeader :title="`${asset.code} - ${asset.name_ar}`" :subtitle="asset.category?.name_ar" :crumbs="[{ label: 'الأصول الثابتة', href: route('accounting.fixed-assets.index') }, { label: asset.code }]">
            <template #actions>
                <button v-if="asset.status !== 'disposed' && $can('accounting.fixed_assets.post')" @click="impairOpen = true" class="px-4 py-2 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">{{ $t('a_asset.record_impairment') }}</button>
                <button v-if="asset.status !== 'disposed' && $can('accounting.fixed_assets.delete')" @click="disposeOpen = true" class="px-4 py-2 rounded-xl border border-rose-300 text-rose-700 text-xs font-bold">{{ $t('a_asset.sell_dispose') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('a_asset.cost')" :value="money(asset.cost)" />
            <StatCard :label="Number(asset.accumulated_impairment) > 0 ? $t('a_asset.acc_imp', { amount: money(asset.accumulated_impairment) }) : $t('a_asset.acc')" :value="money(Number(asset.accumulated_depreciation) + Number(asset.accumulated_impairment || 0))" tone="amber" />
            <StatCard :label="$t('a_asset.carrying_amount')" :value="money(asset.book_value)" tone="emerald" />
            <StatCard :label="units ? $t('a_asset.cur_month') : $t('a_asset.monthly')" :value="money(monthlyAmount)" tone="indigo" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs space-y-2">
                <p><span class="text-slate-500">{{ $t('a_asset.purchase_date') }}</span> <b class="font-mono">{{ date(asset.acquisition_date) }}</b></p>
                <p><span class="text-slate-500">{{ $t('a_asset.depreciation_start') }}</span> <b class="font-mono">{{ date(asset.depreciation_start_date) }}</b></p>
                <p v-if="units"><span class="text-slate-500">{{ $t('a_asset.life_in_units') }}</span> <b class="font-mono">{{ money(asset.total_units) }} {{ asset.unit_label }}</b></p>
                <p v-else><span class="text-slate-500">{{ $t('a_asset.useful_life') }}</span> <b>{{ $t('a_asset.months_n', { n: asset.useful_life_months }) }}</b></p>
                <p><span class="text-slate-500">{{ $t('a_asset.method') }}</span> <b>{{ methods[asset.depreciation_method] }}</b></p>
                <p><span class="text-slate-500">{{ $t('a_asset.salvage_value') }}</span> <b class="font-mono">{{ money(asset.salvage_value) }}</b></p>
                <p><span class="text-slate-500">{{ $t('a_asset.serial_number') }}</span> <b>{{ asset.serial_number || '—' }}</b></p>
                <p><span class="text-slate-500">{{ $t('a_asset.location') }}</span> <b>{{ asset.location || '—' }}</b></p>
                <p v-if="asset.status === 'disposed'" class="text-rose-600 font-bold">{{ $t('a_asset.disposed_on', { date: date(asset.disposal_date), amount: money(asset.disposal_amount) }) }}</p>
                <div v-if="asset.impairments?.length" class="pt-2 border-t border-slate-100">
                    <p class="font-bold text-slate-700 mb-1">{{ $t('a_asset.impairment') }}</p>
                    <p v-for="i in asset.impairments" :key="i.id" class="text-amber-700"><span class="font-mono">{{ date(i.date) }}</span> {{ money(i.amount) }} — {{ i.reason }}</p>
                </div>
                <form v-if="units && asset.status === 'active' && $can('accounting.fixed_assets.edit')" @submit.prevent="saveUsage" class="pt-2 border-t border-slate-100 space-y-2">
                    <p class="font-bold text-slate-700">{{ $t('a_asset.month_usage') }} ({{ asset.unit_label }})</p>
                    <p class="text-slate-400">{{ $t('a_asset.if_the_asset_is_linked_to_equipm') }}</p>
                    <div class="flex gap-2"><DatePicker v-model="usageForm.month" type="month" class="rounded-xl border-slate-200 text-xs" /><input v-model.number="usageForm.units" type="text" inputmode="decimal" data-num step="0.01" min="0" class="w-24 rounded-xl border-slate-200 text-xs" /><button class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('a_asset.save') }}</button></div>
                    <p v-if="usageForm.errors.units" class="text-rose-600">{{ usageForm.errors.units }}</p>
                </form>
            </div>
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('a_asset.month') }}</th><th v-if="units" class="text-end px-4 py-2">{{ $t('a_asset.usage') }}</th><th class="text-end px-4 py-2">{{ $t('a_asset.depreciation_charge') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="d in asset.depreciations" :key="d.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ monthLabel(d.period) }}</td><td v-if="units" class="px-4 py-2 text-end font-mono">{{ usageOf(d.period) ?? '—' }}</td><td class="px-4 py-2 text-end font-mono">{{ money(d.amount) }}</td></tr>
                        <tr v-if="!asset.depreciations.length"><td :colspan="units ? 3 : 2" class="py-8 text-center text-slate-400">{{ $t('a_asset.no_depreciation_posted_yet') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="schedule.length" class="mt-4 bg-white border border-slate-200 rounded-2xl overflow-hidden" data-schedule>
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 text-xs">
                <h3 class="font-black text-slate-700">{{ $t('a_asset.schedule_title', { n: schedule.length }) }}</h3>
                <span class="text-slate-500">{{ $t('a_asset.ends_at', { month: monthLabel(schedule[schedule.length - 1].period), value: money(schedule[schedule.length - 1].book_value) }) }}</span>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('a_asset.month') }}</th><th class="text-end px-4 py-2">{{ $t('a_asset.charge') }}</th><th class="text-end px-4 py-2">{{ $t('a_asset.accumulated') }}</th><th class="text-end px-4 py-2">{{ $t('a_asset.carrying_amount') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in (showAll ? schedule : schedule.slice(0, 12))" :key="r.period" class="border-t border-slate-100">
                        <td class="px-4 py-1.5 font-mono">{{ monthLabel(r.period) }}</td><td class="px-4 py-1.5 text-end font-mono">{{ money(r.amount) }}</td>
                        <td class="px-4 py-1.5 text-end font-mono">{{ money(r.accumulated) }}</td><td class="px-4 py-1.5 text-end font-mono">{{ money(r.book_value) }}</td>
                    </tr>
                </tbody>
            </table></div>
            <button v-if="schedule.length > 12 && !showAll" @click="showAll = true" class="w-full py-2 text-xs font-bold text-indigo-600 border-t border-slate-100">{{ $t('a_asset.show_the_full_schedule') }}</button>
        </div>
        <p v-else-if="units && asset.status === 'active'" class="mt-4 text-xs text-slate-500">{{ $t('a_asset.units_of_production_depreciation') }}</p>

        <Dialog :show="impairOpen" :title="$t('a_asset.record_asset_impairment')" @close="impairOpen = false">
            <div class="space-y-3 text-xs">
                <p class="text-slate-500">{{ $t('a_asset.when_the_recoverable_amount_fall') }}</p>
                <Field :label="$t('a_asset.date')" :error="impairForm.errors.date"><DatePicker v-model="impairForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_asset.impairment_amount')" :error="impairForm.errors.amount" :hint="$t('a_asset.cur_book', { value: money(asset.book_value) })"><input v-model.number="impairForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_asset.reason')" :error="impairForm.errors.reason"><input v-model="impairForm.reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <template #footer><button @click="impair" :disabled="impairForm.processing" class="px-4 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold">{{ $t('a_asset.post_the_impairment') }}</button></template>
        </Dialog>

        <Dialog :show="disposeOpen" :title="$t('a_asset.sell_dispose_of_the_asset')" @close="disposeOpen = false">
            <div class="space-y-3 text-xs">
                <Field :label="$t('a_asset.date')" :error="form.errors.date"><DatePicker v-model="form.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_asset.sale_amount_0_for_scrapping')" :error="form.errors.proceeds"><input v-model.number="form.proceeds" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="flex items-center gap-2"><input v-model="form.taxable" type="checkbox" class="rounded" /> {{ $t('a_asset.taxable_sale') }} {{ $taxRate() }}% ({{ ((parseFloat(form.proceeds) || 0) * $taxRate() / 100).toFixed(2) }})</label>
                <p v-if="form.errors.vat_amount" class="text-rose-600">{{ form.errors.vat_amount }}</p>
                <Field :label="$t('a_asset.receipt_method')"><select v-model="form.method" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">{{ $t('a_asset.bank') }}</option><option value="cash">{{ $t('a_asset.cash') }}</option></select></Field>
                <Field :label="$t('a_asset.cash_box_bank')"><SelectCreate v-model="form.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('a_asset.default')" /></Field>
                <p class="text-slate-500">{{ $t('a_asset.disposal_note', { value: money(asset.book_value) }) }}</p>
            </div>
            <template #footer><button @click="dispose" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">{{ $t('a_asset.confirm_disposal') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

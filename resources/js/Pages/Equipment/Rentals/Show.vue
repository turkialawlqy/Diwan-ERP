<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { ref } from 'vue';
import axios from 'axios';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ rental: Object, readings: Array, summary: Object, suggest: Object, bases: Object });
const r = props.rental;
const unit = props.bases[r.billing_basis];
const isCustomer = r.type === 'customer';

// --- bill a period
const billOpen = ref(false);
const billForm = useForm({ period_from: props.suggest.from, period_to: props.suggest.to, quantity: '', date: '' });
const preview = ref(null);
const loadPreview = async () => {
    preview.value = null;
    if (!billForm.period_from || !billForm.period_to) return;
    try {
        const { data } = await axios.get(route('equipment.rentals.preview', r.id), { params: { period_from: billForm.period_from, period_to: billForm.period_to } });
        preview.value = data;
    } catch (e) { preview.value = { error: e.response?.data?.message || t('eq2_equipment_rentals_show.could_not_calculate') }; }
};
const openBill = () => { billForm.clearErrors(); billForm.quantity = ''; billOpen.value = true; loadPreview(); };
const bill = () => billForm.post(route('equipment.rentals.bill', r.id), { preserveScroll: true, onSuccess: () => { billOpen.value = false; } });

// --- edit / close
const editOpen = ref(false);
const editForm = useForm({ rate: r.rate, min_hours_per_day: r.min_hours_per_day ?? '', end_date: r.end_date?.substring(0, 10) || '', site: r.site || '', with_operator: r.with_operator, notes: r.notes || '' });
const saveEdit = () => editForm.put(route('equipment.rentals.update', r.id), { preserveScroll: true, onSuccess: () => { editOpen.value = false; } });
const closeOpen = ref(false);
const closeForm = useForm({ end_date: r.end_date?.substring(0, 10) || today() });
const close = () => closeForm.post(route('equipment.rentals.close', r.id), { preserveScroll: true, onSuccess: () => { closeOpen.value = false; } });
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq2_equipment_rentals_show.contract_n', { n0: r.number })" />
        <PageHeader :title="$t('eq2_equipment_rentals_show.contract_n', { n0: r.number })" :subtitle="isCustomer ? $t('eq2_equipment_rentals_show.rental_to_n', { n0: r.customer?.name }) : $t('eq2_equipment_rentals_show.operation_on_project_n_n', { n0: r.project?.code, n1: r.project?.name })" :crumbs="[{ label: $t('eq2_equipment_rentals_show.rental_contracts'), href: route('equipment.rentals.index') }, { label: r.number }]">
            <template #actions>
                <button v-if="$can('equipment.rentals.bill')" @click="openBill" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ isCustomer ? $t('eq2_equipment_rentals_show.issue_an_invoice_for_a_period') : $t('eq2_equipment_rentals_show.charge_a_period_to_the_project') }}</button>
                <button v-if="$can('equipment.rentals.edit')" @click="editOpen = true" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_rentals_show.edit') }}</button>
                <button v-if="$can('equipment.rentals.edit') && r.status === 'active'" @click="closeOpen = true" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">{{ $t('eq2_equipment_rentals_show.end_the_contract') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ $t('eq2_equipment_rentals_show.equipment') }}</div><Link :href="route('equipment.show', r.equipment.id)" class="font-bold text-indigo-700">{{ r.equipment.code }} — {{ r.equipment.name }}</Link></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ $t('eq2_equipment_rentals_show.price') }}</div><div class="font-bold">{{ money(r.rate) }} / {{ unit }}</div><div v-if="r.min_hours_per_day" class="text-slate-400">{{ $t('eq2_equipment_rentals_show.minimum_n_h_day', { n0: Number(r.min_hours_per_day) }) }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ $t('eq2_equipment_rentals_show.period') }}</div><div class="font-bold">{{ date(r.start_date) }} — {{ r.end_date ? date(r.end_date) : $t('eq2_equipment_rentals_show.open') }}</div><div :class="r.status === 'active' ? 'text-emerald-600' : 'text-slate-400'">{{ r.status === 'active' ? $t('eq2_equipment_rentals_show.active') : $t('eq2_equipment_rentals_show.ended') }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ $t('eq2_equipment_rentals_show.recorded_working_hours') }}</div><div class="font-mono font-black text-lg">{{ summary.worked_hours }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ isCustomer ? $t('eq2_equipment_rentals_show.total_billed') : $t('eq2_equipment_rentals_show.total_charged') }}</div><div class="font-mono font-black text-lg">{{ money(summary.billed) }}</div><div class="text-slate-400">{{ $t('eq2_equipment_rentals_show.billed_until_n', { n0: r.billed_until ? date(r.billed_until) : '—' }) }}</div></div>
        </div>
        <p v-if="r.site || r.notes" class="mb-4 text-xs text-slate-500"><template v-if="r.site">{{ $t('eq2_equipment_rentals_show.site_n', { n0: r.site }) }} </template>{{ r.notes }}</p>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <section>
                <h3 class="font-black text-slate-700 mb-2">{{ $t('eq2_equipment_rentals_show.billed_periods') }}</h3>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.period') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.quantity') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.amount') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.document') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="b in rental.billings" :key="b.id" class="border-t border-slate-100">
                                <td class="px-3 py-2 whitespace-nowrap">{{ date(b.period_from) }} — {{ date(b.period_to) }}</td>
                                <td class="px-3 py-2">{{ Number(b.quantity) }} {{ unit }}</td>
                                <td class="px-3 py-2 font-mono">{{ money(Number(b.amount) + Number(b.tax_amount)) }}</td>
                                <td class="px-3 py-2"><Link v-if="b.invoice" :href="route('sales.invoices.show', b.invoice.id)" class="text-indigo-600 font-mono">{{ b.invoice.invoice_number }}</Link><span v-else class="text-slate-400">{{ $t('eq2_equipment_rentals_show.project_cost') }}</span></td>
                            </tr>
                            <tr v-if="!rental.billings.length"><td colspan="4" class="px-3 py-6 text-center text-slate-400">{{ $t('eq2_equipment_rentals_show.no_period_billed_yet') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section>
                <h3 class="font-black text-slate-700 mb-2">{{ $t('eq2_equipment_rentals_show.daily_readings_on_this_contract') }}</h3>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto max-h-96">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500 sticky top-0"><tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.date') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.meter') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.working_hours') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_show.operator') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="x in readings" :key="x.id" class="border-t border-slate-100">
                                <td class="px-3 py-2">{{ date(x.date) }}</td>
                                <td class="px-3 py-2 font-mono">{{ x.hours_reading ?? '' }}{{ x.km_reading ? $t('eq2_equipment_rentals_show.n_km', { n0: Number(x.km_reading).toLocaleString('en-US') }) : '' }}</td>
                                <td class="px-3 py-2 font-mono">{{ Number(x.worked_hours) }}<span v-if="r.min_hours_per_day && Number(x.worked_hours) < r.min_hours_per_day" class="text-amber-600"> → {{ Number(r.min_hours_per_day) }}</span></td>
                                <td class="px-3 py-2">{{ x.operator || '' }}</td>
                            </tr>
                            <tr v-if="!readings.length"><td colspan="4" class="px-3 py-6 text-center text-slate-400">{{ $t('eq2_equipment_rentals_show.no_readings_yet') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Dialog :show="billOpen" :title="isCustomer ? $t('eq2_equipment_rentals_show.issue_a_rental_invoice') : $t('eq2_equipment_rentals_show.charge_to_the_project')" size="md" @close="billOpen = false">
            <div class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <Field :label="$t('eq2_equipment_rentals_show.from')" :error="billForm.errors.period_from"><DatePicker v-model="billForm.period_from" @change="loadPreview" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('eq2_equipment_rentals_show.to')" :error="billForm.errors.period_to"><DatePicker v-model="billForm.period_to" @change="loadPreview" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <div v-if="preview" class="rounded-xl bg-slate-50 border border-slate-200 p-3 space-y-1">
                    <p v-if="preview.error" class="text-rose-600">{{ preview.error }}</p>
                    <template v-else>
                        <p v-if="r.billing_basis === 'hourly'">{{ $t('eq2_equipment_rentals_show.recorded_working_hours_2') }} <b>{{ preview.worked_hours }}</b> {{ $t('eq2_equipment_rentals_show.in_n_days', { n0: preview.days_with_readings }) }}<template v-if="r.min_hours_per_day"> {{ $t('eq2_equipment_rentals_show.after_applying_the_minimum') }} <b>{{ preview.quantity }}</b></template></p>
                        <p>{{ $t('eq2_equipment_rentals_show.quantity_2') }} <b>{{ preview.quantity }} {{ unit }}</b> × {{ money(preview.rate) }} = <b>{{ money(preview.amount) }}</b><template v-if="preview.tax"> {{ $t('eq2_equipment_rentals_show.tax_n', { n0: money(preview.tax) }) }} <b>{{ money(preview.total) }}</b></template></p>
                        <p class="text-slate-400">{{ $t('eq2_equipment_rentals_show.the_period_after_matching_the_co', { n0: preview.from, n1: preview.to }) }}</p>
                        <p v-if="preview.overlap" class="text-rose-600 font-bold">{{ $t('eq2_equipment_rentals_show.this_period_overlaps_a_previousl', { n0: date(preview.overlap.period_from), n1: date(preview.overlap.period_to) }) }}</p>
                    </template>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field :label="$t('eq2_equipment_rentals_show.edit_the_quantity_n', { n0: unit })" :error="billForm.errors.quantity" :hint="$t('eq2_equipment_rentals_show.leave_empty_to_use_the_calculate')"><input v-model.number="billForm.quantity" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('eq2_equipment_rentals_show.document_date')" :hint="$t('eq2_equipment_rentals_show.empty_end_of_the_period')"><DatePicker v-model="billForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
            </div>
            <template #footer>
                <button type="button" @click="billOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_rentals_show.cancel') }}</button>
                <button type="button" @click="bill" :disabled="billForm.processing || preview?.overlap" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ isCustomer ? $t('eq2_equipment_rentals_show.issue_the_invoice') : $t('eq2_equipment_rentals_show.charge') }}</button>
            </template>
        </Dialog>

        <Dialog :show="editOpen" :title="$t('eq2_equipment_rentals_show.edit_the_contract')" size="md" @close="editOpen = false">
            <div class="grid grid-cols-2 gap-3 text-xs">
                <Field :label="$t('eq2_equipment_rentals_show.price_per_n', { n0: unit })" :error="editForm.errors.rate" :hint="$t('eq2_equipment_rentals_show.applies_to_periods_not_yet_bille')"><input v-model.number="editForm.rate" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="r.billing_basis === 'hourly'" :label="$t('eq2_equipment_rentals_show.minimum_hours_day')" :error="editForm.errors.min_hours_per_day"><input v-model.number="editForm.min_hours_per_day" type="text" inputmode="decimal" data-num min="0" max="24" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('eq2_equipment_rentals_show.end_date')" :error="editForm.errors.end_date"><DatePicker v-model="editForm.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('eq2_equipment_rentals_show.location')"><input v-model="editForm.site" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="col-span-2 flex items-center gap-2 font-bold"><input v-model="editForm.with_operator" type="checkbox" class="rounded border-slate-300" /> {{ $t('eq2_equipment_rentals_show.with_operator') }}</label>
                <Field :label="$t('eq2_equipment_rentals_show.notes')" class="col-span-2"><textarea v-model="editForm.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <template #footer>
                <button type="button" @click="editOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_rentals_show.cancel') }}</button>
                <button type="button" @click="saveEdit" :disabled="editForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('eq2_equipment_rentals_show.save') }}</button>
            </template>
        </Dialog>

        <Dialog :show="closeOpen" :title="$t('eq2_equipment_rentals_show.end_the_contract')" size="sm" @close="closeOpen = false">
            <div class="text-xs space-y-3">
                <p class="text-slate-600">{{ $t('eq2_equipment_rentals_show.the_equipment_becomes_available') }}</p>
                <Field :label="$t('eq2_equipment_rentals_show.last_day_of_the_contract')" :error="closeForm.errors.end_date"><DatePicker v-model="closeForm.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <template #footer>
                <button type="button" @click="closeOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_rentals_show.cancel') }}</button>
                <button type="button" @click="close" :disabled="closeForm.processing" class="px-5 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('eq2_equipment_rentals_show.end') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

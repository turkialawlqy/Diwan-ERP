<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { taxRate } from '@/utils/tax';
import { onMounted, ref, watch, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { money, date, today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ rentals: Object, filters: Object, equipment: Array, projects: Array, prefillEquipment: Number });
const bases = computed(() => ({ hourly: t('eq2_equipment_rentals_index.hour'), daily: t('eq2_equipment_rentals_index.day'), monthly: t('eq2_equipment_rentals_index.month') }));
const blank = () => ({ type: 'customer', equipment_id: props.prefillEquipment || '', customer_id: null, project_id: '', billing_basis: 'hourly', rate: '', min_hours_per_day: '',
    with_operator: false, tax_rate: taxRate(), start_date: today(), end_date: '', site: '', notes: '' });
const form = useForm(blank());
const open = ref(false);
const create = () => { form.defaults(blank()).reset(); form.clearErrors(); open.value = true; suggestRate(); };
const save = () => form.post(route('equipment.rentals.store'), { preserveScroll: true });

// Default price from the equipment card for the chosen basis.
const suggestRate = () => {
    const e = props.equipment.find((x) => x.id === Number(form.equipment_id));
    if (!e) return;
    form.rate = form.type === 'project'
        ? (form.billing_basis === 'hourly' ? e.internal_hourly_cost : '') || form.rate
        : { hourly: e.hourly_rate, daily: e.daily_rate, monthly: e.monthly_rate }[form.billing_basis] || form.rate;
};
watch(() => [form.equipment_id, form.billing_basis, form.type], suggestRate);

const q = ref(props.filters.q || '');
const filter = (extra = {}) => router.get(route('equipment.rentals.index'), { ...props.filters, q: q.value || undefined, ...extra }, { preserveState: true, replace: true });
onMounted(() => { if (props.prefillEquipment) create(); });
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq2_equipment_rentals_index.equipment_rental_contracts')" />
        <PageHeader :title="$t('eq2_equipment_rentals_index.equipment_rental_contracts')" :subtitle="$t('eq2_equipment_rentals_index.rental_to_customers_with_a_tax_i')" :crumbs="[{ label: $t('eq2_equipment_rentals_index.equipment_2'), href: route('equipment.index') }, { label: $t('eq2_equipment_rentals_index.rental_contracts') }]">
            <template #actions>
                <button v-if="$can('equipment.rentals.create')" @click="create" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('eq2_equipment_rentals_index.new_contract') }}</button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2 mb-3 text-xs">
            <select :value="filters.status || ''" @change="filter({ status: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="الحالة"><option value="">{{ $t('eq2_equipment_rentals_index.all_statuses') }}</option><option value="active">{{ $t('eq2_equipment_rentals_index.active') }}</option><option value="closed">{{ $t('eq2_equipment_rentals_index.ended') }}</option></select>
            <select :value="filters.type || ''" @change="filter({ type: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="النوع"><option value="">{{ $t('eq2_equipment_rentals_index.customers_and_projects') }}</option><option value="customer">{{ $t('eq2_equipment_rentals_index.rental_to_customers') }}</option><option value="project">{{ $t('eq2_equipment_rentals_index.internal_projects') }}</option></select>
            <form @submit.prevent="filter()"><input v-model="q" type="search" :placeholder="$t('eq2_equipment_rentals_index.contract_number_equipment_custom')" class="rounded-xl border-slate-200 text-xs w-56" /></form>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.contract') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.equipment') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.party') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.price') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.period') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.billed_until') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_rentals_index.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in rentals.data" :key="r.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2"><Link :href="route('equipment.rentals.show', r.id)" class="text-indigo-700 font-mono font-bold">{{ r.number }}</Link></td>
                        <td class="px-3 py-2">{{ r.equipment?.code }} — {{ r.equipment?.name }}</td>
                        <td class="px-3 py-2"><span v-if="r.type === 'customer'">{{ r.customer?.name }}</span><span v-else class="text-sky-700">{{ $t('eq2_equipment_rentals_index.project_n_n', { n0: r.project?.code, n1: r.project?.name }) }}</span></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ money(r.rate) }} / {{ bases[r.billing_basis] }}<span v-if="r.min_hours_per_day" class="text-slate-400"> {{ $t('eq2_equipment_rentals_index.minimum_n_h_day', { n0: Number(r.min_hours_per_day) }) }}</span></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ date(r.start_date) }} — {{ r.end_date ? date(r.end_date) : $t('eq2_equipment_rentals_index.open') }}</td>
                        <td class="px-3 py-2">{{ r.billed_until ? date(r.billed_until) : '—' }}</td>
                        <td class="px-3 py-2"><span :class="['px-2 py-0.5 rounded-full font-bold', r.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500']">{{ r.status === 'active' ? $t('eq2_equipment_rentals_index.active') : $t('eq2_equipment_rentals_index.ended') }}</span></td>
                    </tr>
                    <tr v-if="!rentals.data.length"><td colspan="7" class="px-3 py-10 text-center text-slate-400">{{ $t('eq2_equipment_rentals_index.no_contracts') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="rentals" />

        <Dialog :show="open" :title="$t('eq2_equipment_rentals_index.new_rental_contract')" size="lg" @close="open = false">
            <div class="space-y-3 text-xs">
                <div class="flex gap-2" role="radiogroup" aria-label="نوع العقد">
                    <button type="button" role="radio" :aria-checked="form.type === 'customer'" @click="form.type = 'customer'" :class="['flex-1 px-3 py-2 rounded-xl border font-bold', form.type === 'customer' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200']">{{ $t('eq2_equipment_rentals_index.rental_to_a_customer_invoice') }}</button>
                    <button type="button" role="radio" :aria-checked="form.type === 'project'" @click="form.type = 'project'" :class="['flex-1 px-3 py-2 rounded-xl border font-bold', form.type === 'project' ? 'bg-sky-600 text-white border-sky-600' : 'border-slate-200']">{{ $t('eq2_equipment_rentals_index.operation_on_a_company_project') }}</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Field :label="$t('eq2_equipment_rentals_index.equipment')" :error="form.errors.equipment_id">
                        <select v-model="form.equipment_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">{{ $t('eq2_equipment_rentals_index.choose') }}</option><option v-for="e in equipment" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}{{ e.status !== 'available' ? $t('eq2_equipment_rentals_index.occupied') : '' }}</option></select>
                    </Field>
                    <Field v-if="form.type === 'customer'" :label="$t('eq2_equipment_rentals_index.customer')" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" /></Field>
                    <Field v-else :label="$t('eq2_equipment_rentals_index.project')" :error="form.errors.project_id">
                        <select v-model="form.project_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">{{ $t('eq2_equipment_rentals_index.choose') }}</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option></select>
                    </Field>
                    <Field :label="$t('eq2_equipment_rentals_index.billing_basis')">
                        <select v-model="form.billing_basis" class="w-full rounded-xl border-slate-200 text-xs"><option value="hourly">{{ $t('eq2_equipment_rentals_index.hourly_from_daily_readings') }}</option><option value="daily">{{ $t('eq2_equipment_rentals_index.daily') }}</option><option value="monthly">{{ $t('eq2_equipment_rentals_index.monthly') }}</option></select>
                    </Field>
                    <Field :label="$t('eq2_equipment_rentals_index.price_per_n', { n0: bases[form.billing_basis] })" :error="form.errors.rate"><input v-model.number="form.rate" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="form.billing_basis === 'hourly'" :label="$t('eq2_equipment_rentals_index.minimum_hours_per_day')" :error="form.errors.min_hours_per_day" :hint="$t('eq2_equipment_rentals_index.charged_for_every_working_day_ev')"><input v-model.number="form.min_hours_per_day" type="text" inputmode="decimal" data-num min="0" max="24" step="0.5" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="form.type === 'customer'" :label="$t('eq2_equipment_rentals_index.tax_rate')" :error="form.errors.tax_rate"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num min="0" max="100" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('eq2_equipment_rentals_index.start_date')" :error="form.errors.start_date"><DatePicker v-model="form.start_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('eq2_equipment_rentals_index.end_date_optional')" :error="form.errors.end_date"><DatePicker v-model="form.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('eq2_equipment_rentals_index.work_site')" class="md:col-span-2"><input v-model="form.site" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <label class="flex items-center gap-2 font-bold text-slate-700"><input v-model="form.with_operator" type="checkbox" class="rounded border-slate-300" /> {{ $t('eq2_equipment_rentals_index.with_operator') }}</label>
                <Field :label="$t('eq2_equipment_rentals_index.notes')"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <template #footer>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_rentals_index.cancel') }}</button>
                <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('eq2_equipment_rentals_index.create_the_contract') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

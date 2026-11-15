<script setup>
import RowActions from '@/Components/UI/RowActions.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EquipmentForm from '@/Components/Equipment/EquipmentForm.vue';
import MaintenanceStatus from '@/Components/Equipment/MaintenanceStatus.vue';
import { money } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ equipment: Object, filters: Object, categories: Array, counts: Object, statuses: Object, lookups: Object });

const statusColors = { available: 'bg-emerald-50 text-emerald-700', rented: 'bg-indigo-50 text-indigo-700', internal: 'bg-sky-50 text-sky-700', maintenance: 'bg-amber-50 text-amber-700', out_of_service: 'bg-slate-100 text-slate-500' };
const blank = () => ({ code: '', name: '', category: '', model: '', plate_number: '', serial_number: '', ownership: 'owned', fixed_asset_id: null, supplier_id: null,
    supplier_rate: '', supplier_rate_basis: null, meter_type: 'hours', current_hours: 0, current_km: 0, hourly_rate: '', daily_rate: '', monthly_rate: '',
    internal_hourly_cost: '', cost_center_id: null, notes: '' });
const form = useForm(blank());
const open = ref(false);
const create = () => { form.defaults(blank()).reset(); form.clearErrors(); open.value = true; };
const save = () => form.post(route('equipment.store'), { preserveScroll: true });

const q = ref(props.filters.q || '');
const filter = (extra = {}) => router.get(route('equipment.index'), { ...props.filters, q: q.value || undefined, ...extra }, { preserveState: true, replace: true });
const rentalLabel = (r) => r ? (r.customer?.name || (r.project ? `${r.project.code} — ${r.project.name}` : '')) : '';
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq2_equipment_index.equipment_2')" />
        <PageHeader :title="$t('eq2_equipment_index.equipment_2')" :subtitle="$t('eq2_equipment_index.hourly_daily_and_monthly_rental')" :crumbs="[{ label: $t('eq2_equipment_index.equipment_and_maintenance') }, { label: $t('eq2_equipment_index.equipment_2') }]">
            <template #actions>
                <Link v-if="$can('equipment.readings.create')" :href="route('equipment.readings.index')" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_index.enter_daily_readings') }}</Link>
                <button v-if="$can('equipment.assets.create')" @click="create" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('eq2_equipment_index.new_equipment') }}</button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2 mb-3 text-xs">
            <button @click="filter({ status: undefined })" :class="['px-3 py-1.5 rounded-xl border font-bold', !filters.status ? 'bg-slate-800 text-white border-slate-800' : 'bg-white border-slate-200']">{{ $t('eq2_equipment_index.all') }}</button>
            <button v-for="(l, k) in statuses" :key="k" @click="filter({ status: k })" :class="['px-3 py-1.5 rounded-xl border font-bold', filters.status === k ? 'bg-slate-800 text-white border-slate-800' : 'bg-white border-slate-200']">
                {{ l }} <span class="opacity-60">{{ counts[k] || 0 }}</span>
            </button>
            <div class="flex-1"></div>
            <select v-if="categories.length" :value="filters.category || ''" @change="filter({ category: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="الفئة">
                <option value="">{{ $t('eq2_equipment_index.all_categories') }}</option><option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
            </select>
            <form @submit.prevent="filter()"><input v-model="q" type="search" :placeholder="$t('eq2_equipment_index.search_by_name_code_or_plate')" class="rounded-xl border-slate-200 text-xs w-56" /></form>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_index.code') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_index.equipment') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_index.status') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_index.meter') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_index.nearest_maintenance') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_index.price') }}</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="e in equipment.data" :key="e.id" class="border-t border-slate-100 hover:bg-slate-50 cursor-pointer" @click="router.visit(route('equipment.show', e.id))">
                        <td class="px-3 py-2 font-mono font-bold"><Link :href="route('equipment.show', e.id)" class="text-indigo-700" @click.stop>{{ e.code }}</Link></td>
                        <td class="px-3 py-2"><div class="font-bold text-slate-800">{{ e.name }}</div><div class="text-slate-400">{{ e.category }}<template v-if="e.plate_number"> · {{ e.plate_number }}</template><template v-if="e.ownership === 'rented_in'"> {{ $t('eq2_equipment_index.rented_from_n', { n0: e.supplier?.name }) }}</template></div></td>
                        <td class="px-3 py-2"><span :class="[statusColors[e.status], 'px-2 py-0.5 rounded-full font-bold']">{{ statuses[e.status] }}</span><div v-if="e.active_rental" class="text-slate-400 mt-1">{{ rentalLabel(e.active_rental) }}</div></td>
                        <td class="px-3 py-2 font-mono whitespace-nowrap"><div v-if="['hours','both'].includes(e.meter_type)">{{ $t('eq2_equipment_index.n_h', { n0: Number(e.current_hours).toLocaleString('en-US') }) }}</div><div v-if="['km','both'].includes(e.meter_type)">{{ $t('eq2_equipment_index.n_km', { n0: Number(e.current_km).toLocaleString('en-US') }) }}</div><div v-if="e.last_reading_date" class="text-slate-400 font-sans">{{ e.last_reading_date.substring(0, 10) }}</div></td>
                        <td class="px-3 py-2"><template v-if="e.next_maintenance"><div class="text-slate-600 mb-1">{{ e.next_maintenance.name }}</div><MaintenanceStatus :status="e.next_maintenance" compact /></template><span v-else class="text-slate-300">{{ $t('eq2_equipment_index.no_maintenance_schedule') }}</span></td>
                        <td class="px-3 py-2 whitespace-nowrap text-slate-600"><div v-if="Number(e.hourly_rate)">{{ $t('eq2_equipment_index.n_hour', { n0: money(e.hourly_rate) }) }}</div><div v-if="Number(e.daily_rate)">{{ $t('eq2_equipment_index.n_day', { n0: money(e.daily_rate) }) }}</div><div v-if="Number(e.monthly_rate)">{{ $t('eq2_equipment_index.n_month', { n0: money(e.monthly_rate) }) }}</div></td>
                        <td class="px-3 py-2 text-end" @click.stop><RowActions :view="route('equipment.show', e.id)" :remove="$can('equipment.assets.delete') ? route('equipment.destroy', e.id) : null" :confirm="$t('eq2_equipment_index.delete_the_equipment_n', { n0: e.name })" /></td>
                    </tr>
                    <tr v-if="!equipment.data.length"><td colspan="7" class="px-3 py-10 text-center text-slate-400">{{ $t('eq2_equipment_index.no_equipment_yet_add_the_first_o') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="equipment" />

        <datalist id="equipment-categories"><option v-for="c in categories" :key="c" :value="c" /></datalist>
        <Dialog :show="open" :title="$t('eq2_equipment_index.new_equipment')" size="xl" @close="open = false">
            <EquipmentForm :form="form" :lookups="lookups" />
            <template #footer>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_index.cancel') }}</button>
                <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('eq2_equipment_index.save') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

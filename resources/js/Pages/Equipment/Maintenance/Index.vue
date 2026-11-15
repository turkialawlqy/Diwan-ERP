<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money, date, today } from '@/utils/format';

const props = defineProps({ orders: Object, filters: Object, equipment: Array, warehouses: Array, prefill: Number });
const blank = () => ({ equipment_id: props.prefill || '', type: 'preventive', date: today(), hours_reading: '', km_reading: '', technician: '', warehouse_id: props.warehouses[0]?.id || '', notes: '' });
const form = useForm(blank());
const open = ref(false);
const create = () => { form.defaults(blank()).reset(); form.clearErrors(); open.value = true; };
const save = () => form.post(route('equipment.maintenance.store'));
const selected = computed(() => props.equipment.find((e) => e.id === Number(form.equipment_id)));

const q = ref(props.filters.q || '');
const filter = (extra = {}) => router.get(route('equipment.maintenance.index'), { ...props.filters, q: q.value || undefined, ...extra }, { preserveState: true, replace: true });
onMounted(() => { if (props.prefill) create(); });
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq2_equipment_maintenance_index.maintenance_orders')" />
        <PageHeader :title="$t('eq2_equipment_maintenance_index.maintenance_orders')" :subtitle="$t('eq2_equipment_maintenance_index.open_the_order_issue_spare_parts')" :crumbs="[{ label: $t('eq2_equipment_maintenance_index.equipment_2'), href: route('equipment.index') }, { label: $t('eq2_equipment_maintenance_index.maintenance_orders') }]">
            <template #actions>
                <Link :href="route('equipment.maintenance.dashboard')" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_maintenance_index.maintenance_dashboard') }}</Link>
                <button v-if="$can('equipment.maintenance.create')" @click="create" class="px-4 py-2 rounded-xl bg-amber-500 text-white text-xs font-bold">{{ $t('eq2_equipment_maintenance_index.maintenance_order') }}</button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2 mb-3 text-xs">
            <select :value="filters.status || ''" @change="filter({ status: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="الحالة"><option value="">{{ $t('eq2_equipment_maintenance_index.all_statuses') }}</option><option value="open">{{ $t('eq2_equipment_maintenance_index.open') }}</option><option value="completed">{{ $t('eq2_equipment_maintenance_index.completed') }}</option></select>
            <form @submit.prevent="filter()"><input v-model="q" type="search" :placeholder="$t('eq2_equipment_maintenance_index.order_number_or_equipment')" class="rounded-xl border-slate-200 text-xs w-56" /></form>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.order') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.equipment') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.type') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.date') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.technician') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.items_issued') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.parts_cost') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_maintenance_index.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2"><Link :href="route('equipment.maintenance.show', o.id)" class="text-indigo-700 font-mono font-bold">{{ o.number }}</Link></td>
                        <td class="px-3 py-2">{{ o.equipment?.code }} — {{ o.equipment?.name }}</td>
                        <td class="px-3 py-2">{{ o.type === 'preventive' ? $t('eq2_equipment_maintenance_index.preventive') : $t('eq2_equipment_maintenance_index.breakdown_repair') }}</td>
                        <td class="px-3 py-2">{{ date(o.date) }}</td>
                        <td class="px-3 py-2">{{ o.technician || '—' }}</td>
                        <td class="px-3 py-2">{{ o.items_count }}</td>
                        <td class="px-3 py-2 font-mono">{{ money(o.parts_cost) }}</td>
                        <td class="px-3 py-2"><span :class="['px-2 py-0.5 rounded-full font-bold', o.status === 'open' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700']">{{ o.status === 'open' ? $t('eq2_equipment_maintenance_index.open') : $t('eq2_equipment_maintenance_index.completed') }}</span></td>
                    </tr>
                    <tr v-if="!orders.data.length"><td colspan="8" class="px-3 py-10 text-center text-slate-400">{{ $t('eq2_equipment_maintenance_index.no_maintenance_orders') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="orders" />

        <Dialog :show="open" :title="$t('eq2_equipment_maintenance_index.new_maintenance_order')" size="md" @close="open = false">
            <div class="grid grid-cols-2 gap-3 text-xs">
                <Field :label="$t('eq2_equipment_maintenance_index.equipment')" :error="form.errors.equipment_id" class="col-span-2">
                    <select v-model="form.equipment_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">{{ $t('eq2_equipment_maintenance_index.choose') }}</option><option v-for="e in equipment" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option></select>
                </Field>
                <Field :label="$t('eq2_equipment_maintenance_index.type')">
                    <select v-model="form.type" class="w-full rounded-xl border-slate-200 text-xs"><option value="preventive">{{ $t('eq2_equipment_maintenance_index.periodic_preventive_maintenance') }}</option><option value="corrective">{{ $t('eq2_equipment_maintenance_index.breakdown_repair') }}</option></select>
                </Field>
                <Field :label="$t('eq2_equipment_maintenance_index.date')" :error="form.errors.date"><DatePicker v-model="form.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="selected && ['hours','both'].includes(selected.meter_type)" :label="$t('eq2_equipment_maintenance_index.hour_meter')" :hint="$t('eq2_equipment_maintenance_index.current_n', { n0: selected.current_hours })"><input v-model.number="form.hours_reading" type="text" inputmode="decimal" data-num min="0" step="0.1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="selected && ['km','both'].includes(selected.meter_type)" :label="$t('eq2_equipment_maintenance_index.kilometers')" :hint="$t('eq2_equipment_maintenance_index.current_n', { n0: Number(selected.current_km).toLocaleString('en-US') })"><input v-model.number="form.km_reading" type="text" inputmode="decimal" data-num min="0" step="0.1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('eq2_equipment_maintenance_index.technician_workshop')"><input v-model="form.technician" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('eq2_equipment_maintenance_index.issue_warehouse')"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" /></Field>
                <Field :label="$t('eq2_equipment_maintenance_index.work_fault_description')" class="col-span-2"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                <p v-if="form.type === 'preventive'" class="col-span-2 text-slate-500">{{ $t('eq2_equipment_maintenance_index.due_or_upcoming_items_are_added') }}</p>
            </div>
            <template #footer>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('eq2_equipment_maintenance_index.cancel') }}</button>
                <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-amber-500 text-white text-xs font-bold disabled:opacity-50">{{ $t('eq2_equipment_maintenance_index.open_the_order') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

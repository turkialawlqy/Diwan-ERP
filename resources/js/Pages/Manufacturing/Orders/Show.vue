<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import { today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ order: Object, sheet: Object, workCenters: Array, employees: Array, inspections: Array, wip: Number });

const o = computed(() => props.order);
const live = computed(() => ['released', 'in_progress'].includes(o.value.status));
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const post = (name, data = {}) => router.post(route(`manufacturing.orders.${name}`, o.value.id), data, { preserveScroll: true });
const qty = reactive({});
const material = (m, action) => { if (qty[m.id] > 0) { post(action, { material_id: m.id, quantity: qty[m.id] }); qty[m.id] = ''; } };

const time = useForm({ work_center_id: props.order.operations?.[0]?.work_center_id || props.workCenters?.[0]?.id || '', operation_id: '', employee_id: '', minutes: '', quantity: '', date: today() });
const logTime = () => time.post(route('manufacturing.orders.time', o.value.id), { preserveScroll: true, onSuccess: () => time.reset('minutes', 'quantity') });
const pickOp = () => { const op = o.value.operations.find((x) => x.id === time.operation_id); if (op) time.work_center_id = op.work_center_id; };

const rec = useForm({ quantity: '', scrap_quantity: '', scrap_abnormal: false, batch_number: '', date: today() });
const receive = () => rec.post(route('manufacturing.orders.receive', o.value.id), { preserveScroll: true, onSuccess: () => rec.reset('quantity', 'scrap_quantity') });
const remove = () => confirm(t('mfg_manufacturing_orders_show.delete_the_draft_order')) && router.delete(route('manufacturing.orders.destroy', o.value.id));
const inspectionOf = (receiptId) => props.inspections.find((i) => i.source_id === receiptId);
const input = 'w-full text-xs rounded-xl border-slate-200';
const card = 'bg-white border border-slate-200/80 rounded-2xl p-5';
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_orders_show.production_order_n', { n0: o.order_number })" />
        <PageHeader :title="$t('mfg_manufacturing_orders_show.production_order_n', { n0: o.order_number })" :subtitle="o.product?.name_ar" :crumbs="[{ label: $t('mfg_manufacturing_orders_show.manufacturing') }, { label: $t('mfg_manufacturing_orders_show.production_orders'), href: route('manufacturing.orders.index') }, { label: o.order_number }]">
            <template #actions>
                <StatusPill :status="o.status" />
                <button v-if="o.status === 'draft' && $can('manufacturing.orders.approve')" type="button" @click="post('release')" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.release_the_order') }}</button>
                <button v-if="live && $can('manufacturing.orders.post')" type="button" @click="post('issue-all')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.issue_all_materials') }}</button>
                <button v-if="['in_progress', 'completed'].includes(o.status) && $can('manufacturing.orders.post')" type="button" @click="confirm($t('mfg_manufacturing_orders_show.close_the_order_and_post_varianc')) && post('close')" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.close_and_post_variances') }}</button>
                <button v-if="['draft', 'released'].includes(o.status) && $can('manufacturing.orders.cancel')" type="button" @click="confirm($t('mfg_manufacturing_orders_show.cancel_the_order')) && post('cancel')" class="px-4 py-2 rounded-xl border border-rose-300 text-rose-600 text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.cancel') }}</button>
                <button v-if="o.status === 'draft' && $can('manufacturing.orders.delete')" type="button" @click="remove" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.delete') }}</button>
                <a v-if="$can('manufacturing.orders.print')" :href="route('manufacturing.orders.print', o.id)" target="_blank" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.job_card') }}</a>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_orders_show.quantity_received') }}</p><p class="text-xl font-black font-mono">{{ num(o.received_quantity) }} / {{ num(o.quantity) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_orders_show.materials') }}</p><p class="text-xl font-black font-mono">{{ money(o.material_cost) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_orders_show.wages_expenses') }}</p><p class="text-xl font-black font-mono">{{ money(Number(o.labour_cost) + Number(o.overhead_cost)) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_orders_show.work_in_progress') }}</p><p class="text-xl font-black font-mono text-indigo-700">{{ money(wip) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_orders_show.finished_unit_cost') }}</p><p class="text-xl font-black font-mono">{{ sheet.unit_cost === null ? '—' : money(sheet.unit_cost) }}</p></div>
        </div>

        <div :class="[card, 'mb-6']">
            <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_orders_show.materials_2') }}</h2>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="text-slate-500"><tr><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.material') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.required') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.issued') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.returned') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.cost') }}</th><th v-if="live" class="p-2 text-start">{{ $t('mfg_manufacturing_orders_show.issue_return') }}</th></tr></thead>
                <tbody>
                    <tr v-for="m in o.materials" :key="m.id" class="border-t border-slate-100">
                        <td class="p-2 font-bold">{{ m.product?.name_ar }}</td><td class="p-2 font-mono">{{ num(m.planned_quantity) }}</td><td class="p-2 font-mono">{{ num(m.issued_quantity) }}</td><td class="p-2 font-mono">{{ num(m.returned_quantity) }}</td><td class="p-2 font-mono">{{ money(m.cost) }}</td>
                        <td v-if="live && $can('manufacturing.orders.post')" class="p-2 whitespace-nowrap">
                            <input v-model.number="qty[m.id]" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_orders_show.quantity')" class="w-24 text-xs rounded-lg border-slate-200 me-1" />
                            <button type="button" @click="material(m, 'issue')" class="px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold">{{ $t('mfg_manufacturing_orders_show.pay') }}</button>
                            <button type="button" @click="material(m, 'return')" class="px-2 py-1 rounded-lg bg-slate-100 text-slate-600 font-bold ms-1">{{ $t('mfg_manufacturing_orders_show.return') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!o.materials.length"><td colspan="6" class="p-4 text-center text-slate-400">{{ $t('mfg_manufacturing_orders_show.no_planned_materials_no_bill_of') }}</td></tr>
                </tbody>
            </table></div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <div :class="card">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_orders_show.operations_and_time') }}</h2>
                <table class="w-full text-xs mb-4"><thead class="text-slate-500"><tr><th class="text-start p-2">#</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.operation') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.planned_min') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.actual_min') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.status') }}</th></tr></thead>
                    <tbody><tr v-for="op in o.operations" :key="op.id" class="border-t border-slate-100"><td class="p-2 font-mono">{{ op.seq }}</td><td class="p-2">{{ op.name }} <span class="text-slate-400">({{ op.work_center?.name }})</span></td><td class="p-2 font-mono">{{ num(op.planned_minutes) }}</td><td class="p-2 font-mono">{{ num(op.actual_minutes) }}</td><td class="p-2"><StatusPill :status="op.status" /></td></tr>
                    <tr v-if="!o.operations.length"><td colspan="5" class="p-4 text-center text-slate-400">{{ $t('mfg_manufacturing_orders_show.no_operations_no_routing') }}</td></tr></tbody></table>
                <form v-if="live && $can('manufacturing.orders.post')" @submit.prevent="logTime" class="grid grid-cols-2 md:grid-cols-3 gap-2 border-t border-slate-100 pt-3">
                    <select v-model="time.operation_id" @change="pickOp" :class="input"><option value="">{{ $t('mfg_manufacturing_orders_show.operation_optional') }}</option><option v-for="op in o.operations" :key="op.id" :value="op.id">{{ op.seq }} {{ op.name }}</option></select>
                    <SelectCreate v-model="time.work_center_id" :options="workCenters" entity="work_center" label="name" :allow-empty="false" required />
                    <SelectCreate v-model="time.employee_id" :options="employees" entity="employee" label="name" :placeholder="$t('mfg_manufacturing_orders_show.worker_optional')" />
                    <input v-model.number="time.minutes" type="text" inputmode="decimal" data-num step="0.01" :placeholder="$t('mfg_manufacturing_orders_show.minutes')" :class="input" required />
                    <input v-model.number="time.quantity" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_orders_show.completed_quantity')" :class="input" />
                    <button type="submit" :disabled="time.processing" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.record_time') }}</button>
                    <p v-if="time.errors.minutes" class="col-span-full text-xs text-rose-600">{{ time.errors.minutes }}</p>
                </form>
                <table v-if="o.time_logs.length" class="w-full text-xs mt-3"><tbody><tr v-for="t in o.time_logs" :key="t.id" class="border-t border-slate-100"><td class="p-2 font-mono">{{ String(t.date).slice(0, 10) }}</td><td class="p-2">{{ t.work_center?.name }} {{ t.employee?.name ? '· ' + t.employee.name : '' }}</td><td class="p-2 font-mono">{{ $t('mfg_manufacturing_orders_show.n_min', { n0: num(t.minutes) }) }}</td><td class="p-2 font-mono">{{ money(Number(t.labour_cost) + Number(t.overhead_cost)) }}</td></tr></tbody></table>
            </div>

            <div :class="card">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_orders_show.receive_the_finished_product') }}</h2>
                <table class="w-full text-xs mb-4"><thead class="text-slate-500"><tr><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.date') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.good') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.scrap') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.cost') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_orders_show.status') }}</th></tr></thead>
                    <tbody><tr v-for="r in o.receipts" :key="r.id" class="border-t border-slate-100"><td class="p-2 font-mono">{{ String(r.date).slice(0, 10) }}</td><td class="p-2 font-mono">{{ num(r.quantity) }}</td><td class="p-2 font-mono">{{ num(r.scrap_quantity) }}</td><td class="p-2 font-mono">{{ money(r.total_cost) }}</td>
                        <td class="p-2"><StatusPill :status="r.status" /><Link v-if="inspectionOf(r.id)" :href="route('manufacturing.inspections.show', inspectionOf(r.id).id)" class="ms-2 text-indigo-600 font-bold">{{ $t('mfg_manufacturing_orders_show.inspect') }}</Link></td></tr>
                    <tr v-if="!o.receipts.length"><td colspan="5" class="p-4 text-center text-slate-400">{{ $t('mfg_manufacturing_orders_show.no_receipts_yet') }}</td></tr></tbody></table>
                <form v-if="live || o.status === 'completed'" @submit.prevent="receive" class="grid grid-cols-2 gap-2 border-t border-slate-100 pt-3">
                    <template v-if="$can('manufacturing.orders.post')">
                        <input v-model.number="rec.quantity" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_orders_show.good_quantity')" :class="input" />
                        <input v-model.number="rec.scrap_quantity" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_orders_show.scrap_2')" :class="input" />
                        <input v-model="rec.batch_number" :placeholder="$t('mfg_manufacturing_orders_show.batch_number')" :class="input" />
                        <DatePicker v-model="rec.date" :class="input" />
                        <label class="col-span-2 inline-flex items-center gap-2 text-xs"><input v-model="rec.scrap_abnormal" type="checkbox" class="rounded" /> {{ $t('mfg_manufacturing_orders_show.abnormal_scrap_charged_as_a_loss') }}</label>
                        <button type="submit" :disabled="rec.processing" class="col-span-2 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold">{{ $t('mfg_manufacturing_orders_show.take_over') }}</button>
                        <p v-if="rec.errors.quantity || rec.errors.order" class="col-span-2 text-xs text-rose-600">{{ rec.errors.quantity || rec.errors.order }}</p>
                    </template>
                </form>
            </div>
        </div>

        <div :class="card">
            <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_orders_show.cost_sheet_planned_and_actual') }}</h2>
            <div class="grid md:grid-cols-3 gap-4 text-xs">
                <div><p class="text-slate-400 font-bold mb-1">{{ $t('mfg_manufacturing_orders_show.materials_2') }}</p><p class="font-mono">{{ $t('mfg_manufacturing_orders_show.planned_n', { n0: money(sheet.planned.material) }) }}</p><p class="font-mono font-black">{{ $t('mfg_manufacturing_orders_show.actual_n', { n0: money(sheet.actual.material) }) }}</p></div>
                <div><p class="text-slate-400 font-bold mb-1">{{ $t('mfg_manufacturing_orders_show.conversion_wages_expenses') }}</p><p class="font-mono">{{ $t('mfg_manufacturing_orders_show.planned_n', { n0: money(sheet.planned.conversion) }) }}</p><p class="font-mono font-black">{{ $t('mfg_manufacturing_orders_show.actual_n', { n0: money(sheet.actual.labour + sheet.actual.overhead) }) }}</p></div>
                <div><p class="text-slate-400 font-bold mb-1">{{ $t('mfg_manufacturing_orders_show.total') }}</p><p class="font-mono">{{ $t('mfg_manufacturing_orders_show.planned_n', { n0: money(sheet.planned.total) }) }}</p><p class="font-mono font-black">{{ $t('mfg_manufacturing_orders_show.actual_n', { n0: money(sheet.actual.total) }) }}</p><p v-if="o.status === 'closed'" class="font-mono text-rose-600">{{ $t('mfg_manufacturing_orders_show.variances_n', { n0: money(sheet.variance) }) }}</p></div>
            </div>
        </div>
    </AppLayout>
</template>

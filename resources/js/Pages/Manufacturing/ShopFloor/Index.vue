<script setup>
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';

const props = defineProps({ operations: Array, employees: Array });
const entries = reactive({});
const entry = (id) => (entries[id] ||= { minutes: '', quantity: '', employee_id: '' });
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const save = (op) => {
    const e = entry(op.id);
    router.post(route('manufacturing.shop-floor.log'), { operation_id: op.id, minutes: e.minutes, quantity: e.quantity || 0, employee_id: e.employee_id || null }, { preserveScroll: true, onSuccess: () => delete entries[op.id] });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_shopfloor_index.shop_floor')" />
        <PageHeader :title="$t('mfg_manufacturing_shopfloor_index.shop_floor')" :subtitle="$t('mfg_manufacturing_shopfloor_index.open_operations_record_time_and')" :crumbs="[{ label: $t('mfg_manufacturing_shopfloor_index.manufacturing') }, { label: $t('mfg_manufacturing_shopfloor_index.shop_floor') }]" />
        <p v-if="!operations.length" class="bg-white border border-slate-200/80 rounded-2xl p-10 text-center text-slate-400 text-sm">{{ $t('mfg_manufacturing_shopfloor_index.no_open_operations_release_a_pro') }}</p>
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
            <div v-for="op in operations" :key="op.id" class="bg-white border border-slate-200/80 rounded-2xl p-4 space-y-3">
                <div class="flex justify-between items-start">
                    <div><Link :href="route('manufacturing.orders.show', op.order_id)" class="font-mono font-black text-indigo-700">{{ op.order_number }}</Link><p class="text-xs text-slate-500">{{ op.product }} — {{ num(op.quantity) }}</p></div>
                    <StatusPill :status="op.status" />
                </div>
                <p class="text-sm font-black text-slate-800">{{ op.seq }} · {{ op.name }} <span class="text-xs font-normal text-slate-500">({{ op.work_center }})</span></p>
                <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-emerald-500" :style="{ width: Math.min(100, op.planned_minutes ? op.actual_minutes / op.planned_minutes * 100 : 0) + '%' }"></div></div>
                <p class="text-3xs text-slate-500">{{ $t('mfg_manufacturing_shopfloor_index.n_n_min_completed_n', { n0: num(op.actual_minutes), n1: num(op.planned_minutes), n2: num(op.completed) }) }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <input v-model.number="entry(op.id).minutes" type="text" inputmode="decimal" data-num step="0.01" :placeholder="$t('mfg_manufacturing_shopfloor_index.minutes')" class="text-xs rounded-xl border-slate-200" />
                    <input v-model.number="entry(op.id).quantity" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_shopfloor_index.quantity')" class="text-xs rounded-xl border-slate-200" />
                    <SelectCreate v-model="entry(op.id).employee_id" :options="employees" entity="employee" label="name" :placeholder="$t('mfg_manufacturing_shopfloor_index.worker')" />
                </div>
                <button type="button" :disabled="!entry(op.id).minutes" @click="save(op)" class="w-full py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold disabled:opacity-40">{{ $t('mfg_manufacturing_shopfloor_index.record') }}</button>
            </div>
        </div>
    </AppLayout>
</template>

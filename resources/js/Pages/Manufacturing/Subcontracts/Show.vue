<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import { today } from '@/utils/format';

const props = defineProps({ order: Object });
const o = computed(() => props.order);
const left = computed(() => Number(o.value.quantity) - Number(o.value.received_quantity));
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const act = (n) => router.post(route(`manufacturing.subcontracts.${n}`, o.value.id), {}, { preserveScroll: true });
const rec = useForm({ quantity: '', date: today(), notes: '' });
const receive = () => rec.post(route('manufacturing.subcontracts.receive', o.value.id), { preserveScroll: true, onSuccess: () => rec.reset('quantity') });
const input = 'w-full text-xs rounded-xl border-slate-200';
const card = 'bg-white border border-slate-200/80 rounded-2xl p-5';
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_subcontracts_show.order_n', { n0: o.order_number })" />
        <PageHeader :title="$t('mfg_manufacturing_subcontracts_show.subcontract_manufacturing_order', { n0: o.order_number })" :subtitle="`${o.supplier?.name} — ${o.product?.name_ar}`" :crumbs="[{ label: $t('mfg_manufacturing_subcontracts_show.manufacturing') }, { label: $t('mfg_manufacturing_subcontracts_show.subcontract_manufacturing'), href: route('manufacturing.subcontracts.index') }, { label: o.order_number }]">
            <template #actions>
                <StatusPill :status="o.status" />
                <button v-if="o.status === 'draft' && $can('manufacturing.subcontracting.post')" type="button" @click="act('send')" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold">{{ $t('mfg_manufacturing_subcontracts_show.send_raw_materials') }}</button>
                <button v-if="['draft', 'sent'].includes(o.status) && !o.receipts.length && $can('manufacturing.subcontracting.cancel')" type="button" @click="confirm($t('mfg_manufacturing_subcontracts_show.cancel_the_order_and_return_the')) && act('cancel')" class="px-4 py-2 rounded-xl border border-rose-300 text-rose-600 text-xs font-bold">{{ $t('mfg_manufacturing_subcontracts_show.cancel') }}</button>
            </template>
        </PageHeader>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_subcontracts_show.quantity_received') }}</p><p class="text-xl font-black font-mono">{{ num(o.received_quantity) }} / {{ num(o.quantity) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_subcontracts_show.manufacturing_fee_per_unit') }}</p><p class="text-xl font-black font-mono">{{ money(o.service_price) }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_subcontracts_show.from_to') }}</p><p class="text-xs font-bold">{{ o.source_warehouse?.name_ar }} ← {{ o.vendor_warehouse?.name_ar }}</p></div>
            <div :class="card"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_subcontracts_show.finished_product_to') }}</p><p class="text-xs font-bold">{{ o.output_warehouse?.name_ar }}</p></div>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div :class="card">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_subcontracts_show.raw_materials') }}</h2>
                <table class="w-full text-xs"><thead class="text-slate-500"><tr><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.material') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.planned') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.sent') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.consumed') }}</th></tr></thead>
                    <tbody><tr v-for="m in o.materials" :key="m.id" class="border-t border-slate-100"><td class="p-2 font-bold">{{ m.product?.name_ar }}</td><td class="p-2 font-mono">{{ num(m.planned_quantity) }}</td><td class="p-2 font-mono">{{ num(m.sent_quantity) }}</td><td class="p-2 font-mono">{{ num(m.consumed_quantity) }}</td></tr>
                    <tr v-if="!o.materials.length"><td colspan="4" class="p-4 text-center text-slate-400">{{ $t('mfg_manufacturing_subcontracts_show.no_raw_materials_fee_only_manufa') }}</td></tr></tbody></table>
            </div>
            <div :class="card">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_subcontracts_show.receipt') }}</h2>
                <table class="w-full text-xs mb-4"><thead class="text-slate-500"><tr><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.date') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.quantity') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.raw_materials_2') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.fee') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_subcontracts_show.total') }}</th></tr></thead>
                    <tbody><tr v-for="r in o.receipts" :key="r.id" class="border-t border-slate-100"><td class="p-2 font-mono">{{ String(r.date).slice(0, 10) }}</td><td class="p-2 font-mono">{{ num(r.quantity) }}</td><td class="p-2 font-mono">{{ money(r.material_cost) }}</td><td class="p-2 font-mono">{{ money(r.service_cost) }}</td><td class="p-2 font-mono font-bold">{{ money(r.total_cost) }}</td></tr>
                    <tr v-if="!o.receipts.length"><td colspan="5" class="p-4 text-center text-slate-400">{{ $t('mfg_manufacturing_subcontracts_show.no_receipts') }}</td></tr></tbody></table>
                <form v-if="['sent', 'partially_received'].includes(o.status) && $can('manufacturing.subcontracting.post')" @submit.prevent="receive" class="grid grid-cols-3 gap-2 border-t border-slate-100 pt-3">
                    <input v-model.number="rec.quantity" type="text" inputmode="decimal" data-num step="0.0001" :placeholder="$t('mfg_manufacturing_subcontracts_show.quantity_n_remaining', { n0: num(left) })" :class="input" required />
                    <DatePicker v-model="rec.date" :class="input" />
                    <button type="submit" :disabled="rec.processing" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold">{{ $t('mfg_manufacturing_subcontracts_show.take_over') }}</button>
                    <p v-if="rec.errors.quantity || rec.errors.order" class="col-span-3 text-xs text-rose-600">{{ rec.errors.quantity || rec.errors.order }}</p>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

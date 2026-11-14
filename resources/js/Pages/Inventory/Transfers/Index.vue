<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import ImportLines from '@/Components/Documents/ImportLines.vue';
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { date, today } from '@/utils/format';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ transfers: Object, warehouses: Array, filters: Object });

const open = ref(false);
const form = useForm({ from_warehouse_id: '', to_warehouse_id: '', transfer_date: today(), notes: '', items: [] });
const lookup = ref('');
const results = ref([]);
const search = async () => {
    if (lookup.value.trim().length < 2) return;
    results.value = (await axios.get(route('lookup.products'), { params: { q: lookup.value } })).data.data;
};
const add = (p) => { form.items.push({ product_id: p.id, name: p.name_ar, quantity: 1 }); results.value = []; lookup.value = ''; };
const save = () => form.post(route('inventory.stock-transfers.store'));
const statusLabel = computed(() => ({ draft: [t('i_common.draft'), 'text-slate-500'], in_transit: [t('i_common.in_transit'), 'text-amber-600'], received: [t('i_common.received'), 'text-emerald-600'], cancelled: [t('i_common.cancelled'), 'text-slate-400'] }));

const importLines = (lines, replace) => {
    const rows = lines.map((l) => ({ product_id: l.product_id, name: l.item_name, quantity: l.quantity }));
    form.items = replace ? rows : [...form.items, ...rows];
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_transfers_index.stock_transfers')" />
        <PageHeader :title="$t('i_transfers_index.stock_transfers')" :subtitle="$t('i_transfers_index.a_two_step_transfer_send_from_th')" :crumbs="[{ label: 'المستودعات' }, { label: 'التحويلات' }]">
            <template #actions><button v-if="$can('inventory.transfers.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('i_transfers_index.transfer_order') }}</button></template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('i_transfers_index.number') }}</th><th class="text-start px-4 py-2">{{ $t('i_transfers_index.from') }}</th><th class="text-start px-4 py-2">{{ $t('i_transfers_index.to') }}</th><th class="px-4 py-2">{{ $t('ui.date') }}</th><th class="px-4 py-2">{{ $t('i_transfers_index.items') }}</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="t in transfers.data" :key="t.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono">{{ t.transfer_number }}</td>
                        <td class="px-4 py-2">{{ t.from_warehouse?.name_ar }}</td>
                        <td class="px-4 py-2">{{ t.to_warehouse?.name_ar }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(t.transfer_date) }}</td>
                        <td class="px-4 py-2 text-center">{{ t.items_count }}</td>
                        <td class="px-4 py-2 text-center"><span :class="statusLabel[t.status][1]" class="font-bold">{{ statusLabel[t.status][0] }}</span></td>
                        <td class="px-4 py-2 text-end"><Link :href="route('inventory.stock-transfers.show', t.id)" class="text-indigo-600 font-bold">{{ $t('i_transfers_index.open') }}</Link></td>
                    </tr>
                    <tr v-if="!transfers.data.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="transfers" />
        </div>

        <Dialog :show="open" :title="$t('i_transfers_index.stock_transfer_order')" size="lg" @close="open = false">
            <div class="grid grid-cols-3 gap-3 text-xs mb-3">
                <Field :label="$t('i_transfers_index.from_warehouse')" :error="form.errors.from_warehouse_id"><SelectCreate v-model="form.from_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" /></Field>
                <Field :label="$t('i_transfers_index.to_warehouse')" :error="form.errors.to_warehouse_id"><SelectCreate v-model="form.to_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" /></Field>
                <Field :label="$t('i_transfers_index.date')"><DatePicker v-model="form.transfer_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="relative mb-2">
                <div class="flex gap-2 mb-2"><ImportLines kind="stock" @imported="importLines" /></div>
                <input v-model="lookup" @input="search" :placeholder="$t('i_transfers_index.search_for_an_item_to_add')" class="w-full rounded-xl border-slate-200 text-xs" />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto">
                    <button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="add(p)" @keydown.enter.prevent="add(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">{{ p.name_ar }} <span class="text-slate-400">{{ p.sku }}</span></button>
                </div>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr v-for="(item, i) in form.items" :key="i" class="border-t border-slate-100">
                    <td class="py-1.5">{{ item.name }}</td>
                    <td class="py-1.5 w-32"><input v-model.number="item.quantity" type="text" inputmode="decimal" data-num step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="py-1.5 w-8 text-center"><button @click="form.items.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                </tr>
            </table></div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600 mt-1">{{ msg }}</p>
            <template #footer><button @click="save" :disabled="form.processing || !form.items.length" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

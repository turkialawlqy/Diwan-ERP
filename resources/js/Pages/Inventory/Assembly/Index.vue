<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { qty, money, date, today } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ boms: Array, orders: Object, warehouses: Array });

const bomOpen = ref(false);
const bomForm = useForm({ product_id: '', product_name: '', name: '', output_quantity: 1, items: [] });
const lookup = ref('');
const results = ref([]);
const target = ref('component');
const search = async (who) => {
    target.value = who;
    if (lookup.value.trim().length < 2) return;
    results.value = (await axios.get(route('lookup.products'), { params: { q: lookup.value } })).data.data;
};
const pick = (p) => {
    if (target.value === 'product') { bomForm.product_id = p.id; bomForm.product_name = p.name_ar; bomForm.name ||= t('i_assembly_index.recipe') + ' ' + p.name_ar; }
    else bomForm.items.push({ component_id: p.id, name: p.name_ar, quantity: 1 });
    results.value = []; lookup.value = '';
};
const saveBom = () => bomForm.post(route('inventory.assembly.boms.store'), { preserveScroll: true, onSuccess: () => { bomOpen.value = false; bomForm.reset(); } });

const orderForm = useForm({ bom_id: '', warehouse_id: props.warehouses[0]?.id ?? '', quantity: 1, date: today() });
const run = () => orderForm.post(route('inventory.assembly.orders.store'), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_assembly_index.assembly_and_manufacturing')" />
        <PageHeader :title="$t('i_assembly_index.assembly_and_bills_of_materials')" :subtitle="$t('i_assembly_index.issue_the_components_and_produce')" :crumbs="[{ label: 'المستودعات' }, { label: 'التجميع' }]">
            <template #actions><button v-if="$can('inventory.assembly.create')" @click="bomOpen = true" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('i_assembly_index.new_bill_of_materials') }}</button></template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
            <div class="lg:col-span-2 space-y-3">
                <div v-for="bom in boms" :key="bom.id" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <p class="font-black text-slate-800">{{ bom.name }} <span class="text-slate-400 font-normal">→ {{ qty(bom.output_quantity) }} × {{ bom.product?.name_ar }}</span></p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in bom.items" :key="item.id" class="flex justify-between"><span>{{ item.component?.name_ar }}</span><span class="font-mono">{{ qty(item.quantity) }} · {{ $t('i_assembly_index.cost') }} {{ money(item.quantity * item.component?.average_cost) }}</span></li>
                    </ul>
                </div>
                <p v-if="!boms.length" class="text-xs text-slate-400">{{ $t('i_assembly_index.no_bills_of_materials') }}</p>
            </div>

            <form v-if="$can('inventory.assembly.create') && boms.length" @submit.prevent="run" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3 text-xs h-fit">
                <h3 class="font-black text-slate-800">{{ $t('i_assembly_index.assembly_order') }}</h3>
                <Field :label="$t('i_assembly_index.bill_of_materials')" :error="orderForm.errors.bom_id"><select v-model="orderForm.bom_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="b in boms" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <Field :label="$t('i_assembly_index.warehouse')"><SelectCreate v-model="orderForm.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" /></Field>
                <Field :label="$t('i_assembly_index.quantity_produced')" :error="orderForm.errors.quantity"><input v-model.number="orderForm.quantity" type="text" inputmode="decimal" data-num step="0.0001" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('i_assembly_index.date')"><DatePicker v-model="orderForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <p v-if="orderForm.errors.items" class="text-rose-600">{{ orderForm.errors.items }}</p>
                <button :disabled="orderForm.processing" class="w-full rounded-xl bg-emerald-600 text-white font-bold py-2">{{ $t('i_assembly_index.execute') }}</button>
            </form>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('i_assembly_index.number') }}</th><th class="text-start px-4 py-2">{{ $t('i_assembly_index.product') }}</th><th class="px-4 py-2">{{ $t('i_assembly_index.warehouse') }}</th><th class="text-end px-4 py-2">{{ $t('i_assembly_index.quantity') }}</th><th class="text-end px-4 py-2">{{ $t('i_assembly_index.cost') }}</th><th class="px-4 py-2">{{ $t('ui.date') }}</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono">{{ o.order_number }}</td><td class="px-4 py-2">{{ o.bom?.product?.name_ar }}</td><td class="px-4 py-2 text-center">{{ o.warehouse?.name_ar }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ qty(o.quantity) }}</td><td class="px-4 py-2 text-end font-mono">{{ money(o.total_cost) }}</td><td class="px-4 py-2 text-center font-mono">{{ date(o.date) }}</td>
                    </tr>
                </tbody>
            </table>
            <Pagination :paginator="orders" />
        </div>

        <Dialog :show="bomOpen" :title="$t('i_assembly_index.new_bill_of_materials')" size="lg" @close="bomOpen = false">
            <div class="space-y-3 text-xs">
                <div class="relative">
                    <Field :label="$t('i_assembly_index.finished_product')" :error="bomForm.errors.product_id"><input v-model="lookup" @input="search('product')" :placeholder="bomForm.product_name || $t('i_assembly_index.search')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field :label="$t('i_assembly_index.list_name')"><input v-model="bomForm.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('i_assembly_index.production_quantity_per_list')"><input v-model.number="bomForm.output_quantity" type="text" inputmode="decimal" data-num step="0.0001" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <Field :label="$t('i_assembly_index.add_a_component')"><input v-model="lookup" @input="search('component')" :placeholder="$t('i_assembly_index.search_for_a_component')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div v-if="results.length" class="bg-white border rounded-xl shadow max-h-40 overflow-y-auto">
                    <button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="pick(p)" @keydown.enter.prevent="pick(p)" class="block w-full text-start px-3 py-2 hover:bg-indigo-50">{{ p.name_ar }}</button>
                </div>
                <table class="w-full">
                    <tr v-for="(item, i) in bomForm.items" :key="i" class="border-t border-slate-100">
                        <td class="py-1.5">{{ item.name }}</td>
                        <td class="py-1.5 w-32"><input v-model.number="item.quantity" type="text" inputmode="decimal" data-num step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="py-1.5 w-8"><button @click="bomForm.items.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                    </tr>
                </table>
                <p v-for="(msg, key) in bomForm.errors" :key="key" class="text-rose-600">{{ msg }}</p>
            </div>
            <template #footer><button @click="saveBom" :disabled="bomForm.processing || !bomForm.product_id || !bomForm.items.length" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

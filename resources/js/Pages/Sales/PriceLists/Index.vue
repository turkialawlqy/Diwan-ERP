<script setup>
import { useI18n } from 'vue-i18n';
import ImportButton from '@/Components/UI/ImportButton.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import ImportLines from '@/Components/Documents/ImportLines.vue';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money } from '@/utils/format';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ lists: Array });

const open = ref(false);
const editing = ref(null);
const form = useForm({ name: '', is_default: false, is_active: true, valid_from: '', valid_to: '', items: [] });
const edit = (list) => {
    editing.value = list;
    Object.assign(form, { name: list.name, is_default: !!list.is_default, is_active: !!list.is_active, valid_from: list.valid_from?.substring(0, 10) || '', valid_to: list.valid_to?.substring(0, 10) || '',
        items: list.items.map((i) => ({ product_id: i.product_id, name: i.product?.name_ar, base: i.product?.selling_price, min_quantity: Number(i.min_quantity), price: Number(i.price) })) });
    open.value = true;
};
const create = () => { editing.value = null; form.reset(); form.items = []; open.value = true; };
const term = ref('');
const results = ref([]);
const search = async () => { results.value = term.value.length < 1 ? [] : (await axios.get(route('lookup.products'), { params: { q: term.value } })).data.data; };
// Price list lines from a spreadsheet: code, price and (optionally) the minimum quantity in the quantity column.
const importPrices = (lines, replace) => {
    const rows = lines.filter((l) => l.product_id).map((l) => ({ product_id: l.product_id, name: l.item_name, base: null, min_quantity: Number(l.quantity) || 1, price: Number(l.unit_price) }));
    form.items = replace ? rows : [...form.items, ...rows];
};
const add = (p) => { form.items.push({ product_id: p.id, name: p.name_ar, base: p.selling_price, min_quantity: 1, price: Number(p.selling_price) }); results.value = []; term.value = ''; };
const save = () => editing.value
    ? form.put(route('sales.price-lists.update', editing.value.id), { onSuccess: () => (open.value = false) })
    : form.post(route('sales.price-lists.store'), { onSuccess: () => (open.value = false) });
const destroy = (list) => { if (confirm(t('s_pricelists.confirm_delete', { name: list.name }))) router.delete(route('sales.price-lists.destroy', list.id)); };
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_pricelists.price_lists')" />
        <PageHeader :title="$t('s_pricelists.price_lists')" :subtitle="$t('s_pricelists.special_prices_per_customer_clas')" :crumbs="[{ label: 'المبيعات' }, { label: 'قوائم الأسعار' }]">
            <template #actions><button v-if="$can('sales.price_lists.create')" @click="create" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('s_pricelists.new_list') }}</button>
                <ImportButton v-if="$can('sales.price_lists.create')" type="price_lists" :title="$t('s_pricelists.import_price_lists_from_excel')" />
</template>
        </PageHeader>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div v-for="list in lists" :key="list.id" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <div class="flex justify-between items-start">
                    <h3 class="font-black text-slate-800">{{ list.name }} <span v-if="list.is_default" class="text-indigo-600">{{ $t('s_pricelists.default') }}</span></h3>
                    <span :class="list.is_active ? 'text-emerald-600' : 'text-slate-400'" class="font-bold">{{ list.is_active ? $t('s_pricelists.active') : $t('s_pricelists.suspended') }}</span>
                </div>
                <p class="text-slate-500 mt-1">{{ $t('s_pricelists.counts', { items: list.items_count, customers: list.customers_count }) }}</p>
                <div class="flex gap-3 mt-3">
                    <button v-if="$can('sales.price_lists.edit')" @click="edit(list)" class="text-indigo-600 font-bold">{{ $t('s_pricelists.edit') }}</button>
                    <button v-if="$can('sales.price_lists.delete')" @click="destroy(list)" class="text-rose-600 font-bold">{{ $t('s_pricelists.delete') }}</button>
                </div>
            </div>
            <p v-if="!lists.length" class="text-xs text-slate-400">{{ $t('s_pricelists.no_price_lists') }}</p>
        </div>
        <Dialog :show="open" :title="editing ? $t('s_pricelists.edit') : $t('s_pricelists.new')" size="lg" @close="open = false">
            <div class="grid grid-cols-4 gap-3 mb-3">
                <Field :label="$t('s_pricelists.name')" :error="form.errors.name" class="col-span-2"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_pricelists.from')"><DatePicker v-model="form.valid_from" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('s_pricelists.to')"><DatePicker v-model="form.valid_to" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="text-xs flex items-center gap-2"><input v-model="form.is_default" type="checkbox" class="rounded" /> {{ $t('s_pricelists.default_for_all_customers') }}</label>
                <label class="text-xs flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded" /> {{ $t('s_pricelists.active') }}</label>
            </div>
            <div class="relative mb-2">
                <div class="mb-2"><ImportLines kind="sales" :label="$t('s_pricelists.import_prices_from_excel')" @imported="importPrices" /> <span class="text-3xs text-slate-400">{{ $t('s_pricelists.item_code_price_and_quantity_min') }}</span></div>
                <input v-model="term" @input="search" :placeholder="$t('s_pricelists.add_an_item')" class="w-full rounded-xl border-slate-200 text-xs" />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto">
                    <button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="add(p)" @keydown.enter.prevent="add(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">{{ p.name_ar }}</button>
                </div>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr class="text-slate-500"><th class="text-start">{{ $t('s_pricelists.item') }}</th><th>{{ $t('s_pricelists.base_price') }}</th><th class="w-28">{{ $t('s_pricelists.from_quantity') }}</th><th class="w-28">{{ $t('s_pricelists.price') }}</th><th></th></tr>
                <tr v-for="(i, idx) in form.items" :key="idx" class="border-t border-slate-100">
                    <td class="py-1.5">{{ i.name }}</td>
                    <td class="text-center font-mono">{{ money(i.base) }}</td>
                    <td><input v-model.number="i.min_quantity" type="text" inputmode="decimal" data-num step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td><input v-model.number="i.price" type="text" inputmode="decimal" data-num step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="text-center"><button @click="form.items.splice(idx, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                </tr>
            </table></div>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

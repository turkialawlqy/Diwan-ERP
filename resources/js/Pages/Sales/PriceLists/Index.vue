<script setup>
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
const destroy = (list) => { if (confirm(`حذف قائمة "${list.name}"؟`)) router.delete(route('sales.price-lists.destroy', list.id)); };
</script>

<template>
    <AppLayout>
        <Head title="قوائم الأسعار" />
        <PageHeader title="قوائم الأسعار" subtitle="أسعار خاصة لكل فئة عملاء مع شرائح كميات؛ تُطبّق تلقائياً في أوامر البيع" :crumbs="[{ label: 'المبيعات' }, { label: 'قوائم الأسعار' }]">
            <template #actions><button v-if="$can('sales.price_lists.create')" @click="create" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> قائمة جديدة</button></template>
        </PageHeader>
        <div class="grid md:grid-cols-3 gap-4">
            <div v-for="list in lists" :key="list.id" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <div class="flex justify-between items-start">
                    <h3 class="font-black text-slate-800">{{ list.name }} <span v-if="list.is_default" class="text-indigo-600">(افتراضية)</span></h3>
                    <span :class="list.is_active ? 'text-emerald-600' : 'text-slate-400'" class="font-bold">{{ list.is_active ? 'فعالة' : 'موقوفة' }}</span>
                </div>
                <p class="text-slate-500 mt-1">{{ list.items_count }} صنف · {{ list.customers_count }} عميل</p>
                <div class="flex gap-3 mt-3">
                    <button v-if="$can('sales.price_lists.edit')" @click="edit(list)" class="text-indigo-600 font-bold">تعديل</button>
                    <button v-if="$can('sales.price_lists.delete')" @click="destroy(list)" class="text-rose-600 font-bold">حذف</button>
                </div>
            </div>
            <p v-if="!lists.length" class="text-xs text-slate-400">لا توجد قوائم أسعار.</p>
        </div>
        <Dialog :show="open" :title="editing ? 'تعديل قائمة الأسعار' : 'قائمة أسعار جديدة'" size="lg" @close="open = false">
            <div class="grid grid-cols-4 gap-3 mb-3">
                <Field label="الاسم" :error="form.errors.name" class="col-span-2"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="من"><input v-model="form.valid_from" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="إلى"><input v-model="form.valid_to" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="text-xs flex items-center gap-2"><input v-model="form.is_default" type="checkbox" class="rounded" /> افتراضية لكل العملاء</label>
                <label class="text-xs flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded" /> فعالة</label>
            </div>
            <div class="relative mb-2">
                <div class="mb-2"><ImportLines kind="sales" label="استيراد الأسعار من Excel" @imported="importPrices" /> <span class="text-3xs text-slate-400">رمز الصنف، السعر، والكمية = الحد الأدنى للكمية</span></div>
                <input v-model="term" @input="search" placeholder="أضف صنفاً..." class="w-full rounded-xl border-slate-200 text-xs" />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto">
                    <button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="add(p)" @keydown.enter.prevent="add(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">{{ p.name_ar }}</button>
                </div>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr class="text-slate-500"><th class="text-start">الصنف</th><th>السعر الأساسي</th><th class="w-28">من كمية</th><th class="w-28">السعر</th><th></th></tr>
                <tr v-for="(i, idx) in form.items" :key="idx" class="border-t border-slate-100">
                    <td class="py-1.5">{{ i.name }}</td>
                    <td class="text-center font-mono">{{ money(i.base) }}</td>
                    <td><input v-model="i.min_quantity" type="number" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td><input v-model="i.price" type="number" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="text-center"><button @click="form.items.splice(idx, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                </tr>
            </table></div>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

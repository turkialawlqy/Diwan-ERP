<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, reactive } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ bom: Object, products: Array, routings: Array });
const editing = !!props.bom?.id;
const b = props.bom;
const form = useForm({
    product_id: b.product_id || '', code: b.code || '', name: b.name || '', version: b.version || '1', is_default: b.is_default ?? true, is_active: b.is_active ?? true,
    output_quantity: b.output_quantity ?? 1, scrap_percent: b.scrap_percent ?? 0, routing_id: b.routing_id || '', notes: b.notes || '',
    items: b.items?.length ? b.items.map((i) => ({ component_id: i.component_id, quantity: i.quantity, scrap_percent: i.scrap_percent })) : [{ component_id: '', quantity: 1, scrap_percent: 0 }],
});
useUnsavedGuard(form);
const productSearch = { route: 'lookup.products', params: { stockable: 1, cost: 1 } };
// Average cost of the components: from the page's first records, and of each one found by search as it is picked.
const costs = reactive(Object.fromEntries(props.products.map((p) => [p.id, Number(p.average_cost) || 0])));
const rememberCost = (p) => { if (p?.id && p.average_cost !== undefined) costs[p.id] = Number(p.average_cost) || 0; };
const cost = computed(() => {
    const sum = form.items.reduce((a, i) => a + (Number(i.quantity) || 0) * (1 + (Number(i.scrap_percent) || 0) / 100) * (costs[i.component_id] || 0), 0);
    return (sum * (1 + (Number(form.scrap_percent) || 0) / 100)) / (Number(form.output_quantity) || 1);
});
const submit = () => (editing ? form.put(route('manufacturing.boms.update', b.id)) : form.post(route('manufacturing.boms.store')));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? 'تعديل قائمة مواد' : 'قائمة مواد جديدة'" />
        <PageHeader :title="editing ? 'تعديل قائمة مواد' : 'قائمة مواد جديدة'" :crumbs="[{ label: 'التصنيع' }, { label: 'قوائم المواد', href: route('manufacturing.boms.index') }, { label: editing ? 'تعديل' : 'جديدة' }]" />
        <form @submit.prevent="submit" class="space-y-5">
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field label="المنتج النهائي *" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" :search="productSearch" entity="product" :label="(p) => `${p.name_ar} (${p.sku})`" placeholder="— اختر —" required /></Field>
                <Field label="اسم القائمة *" :error="form.errors.name"><input v-model="form.name" :class="input" required /></Field>
                <Field label="الرمز"><input v-model="form.code" :class="input" class="font-mono" /></Field>
                <Field label="الإصدار *"><input v-model="form.version" :class="input" class="font-mono" /></Field>
                <Field label="كمية الناتج لكل دفعة *" :error="form.errors.output_quantity"><input v-model.number="form.output_quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></Field>
                <Field label="هالك القائمة %" hint="يُضاف على كل المكونات"><input v-model.number="form.scrap_percent" type="text" inputmode="decimal" data-num step="0.01" :class="input" /></Field>
                <Field label="مسار التشغيل"><select v-model="form.routing_id" :class="input"><option value="">— بلا مسار —</option><option v-for="r in routings" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }}</option></select></Field>
                <div class="flex items-center gap-6"><label class="inline-flex items-center gap-2 text-xs"><input v-model="form.is_default" type="checkbox" class="rounded" /> الإصدار الافتراضي للمنتج</label><label class="inline-flex items-center gap-2 text-xs"><input v-model="form.is_active" type="checkbox" class="rounded" /> نشطة</label></div>
            </div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
                <div class="flex items-center justify-between mb-3"><h2 class="font-black text-slate-800 text-sm">المكونات</h2><button type="button" @click="form.items.push({ component_id: '', quantity: 1, scrap_percent: 0 })" class="text-xs font-bold text-indigo-600 inline-flex items-center gap-1"><PlusIcon class="w-4 h-4" /> إضافة مكوّن</button></div>
                <p v-if="form.errors.items" class="text-xs text-rose-600 mb-2">{{ form.errors.items }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-slate-500"><tr><th class="text-start p-2">المكوّن</th><th class="text-start p-2 w-36">الكمية لكل دفعة</th><th class="text-start p-2 w-32">هالك %</th><th class="w-10"></th></tr></thead>
                        <tbody>
                            <tr v-for="(it, i) in form.items" :key="i" class="border-t border-slate-100">
                                <td class="p-2"><SelectCreate v-model="it.component_id" :options="products" :search="productSearch" entity="product" :label="(p) => `${p.name_ar} (${p.sku})`" placeholder="— اختر —" required @picked="rememberCost" /></td>
                                <td class="p-2"><input v-model.number="it.quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></td>
                                <td class="p-2"><input v-model.number="it.scrap_percent" type="text" inputmode="decimal" data-num step="0.01" :class="input" /></td>
                                <td class="p-2"><button type="button" :disabled="form.items.length <= 1" @click="form.items.splice(i, 1)" class="p-1 text-slate-400 hover:text-rose-600 disabled:opacity-20"><TrashIcon class="w-4 h-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-500 mt-3">تكلفة مواد الوحدة بمتوسط التكلفة الحالي: <b class="font-mono">{{ cost.toFixed(4) }}</b></p>
            </div>
            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">حفظ</button>
                <Link :href="route('manufacturing.boms.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>

<script setup>
import ImportLines from '@/Components/Documents/ImportLines.vue';
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { qty, amount } from '@/utils/format';

const props = defineProps({ count: Object, items: Object, summary: Object, filters: Object });

const editable = props.count.status === 'draft';
const entered = reactive(Object.fromEntries(props.items.data.map((i) => [i.product_id, i.counted_quantity ?? ''])));
const filters = reactive({ search: props.filters.search ?? '', only: props.filters.only ?? '' });
const apply = () => router.get(route('inventory.counts.show', props.count.id), filters, { preserveState: true });

const form = useForm({ lines: [] });
const save = () => {
    form.lines = Object.entries(entered).filter(([, v]) => v !== '' && v !== null).map(([product_id, counted_quantity]) => ({ product_id: Number(product_id), counted_quantity }));
    form.put(route('inventory.counts.update', props.count.id), { preserveScroll: true });
};
const approve = () => confirm('اعتماد الجرد؟ سيتم تعديل أرصدة الأصناف المجرودة وترحيل قيد الفروقات.') && router.post(route('inventory.counts.approve', props.count.id));
const cancel = () => confirm('إلغاء الجرد؟') && router.post(route('inventory.counts.cancel', props.count.id));

// Spot counts: add a product by name / barcode.
const lookup = ref('');
const results = ref([]);
const search = async () => {
    if (lookup.value.trim().length < 2) return;
    results.value = (await axios.get(route('lookup.products'), { params: { q: lookup.value } })).data.data;
};
const addProduct = (p) => {
    useForm({ lines: [{ product_id: p.id, counted_quantity: null }] }).put(route('inventory.counts.update', props.count.id), { preserveScroll: true });
    results.value = [];
    lookup.value = '';
};

// A count sheet from a spreadsheet: code and counted quantity, saved at once (products not yet in the count are added).
const importCounts = (lines) => {
    useForm({ lines: lines.map((l) => ({ product_id: l.product_id, counted_quantity: l.quantity })) })
        .put(route('inventory.counts.update', props.count.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="`جرد ${count.count_number}`" />
        <PageHeader :title="`جرد ${count.count_number}`" :subtitle="`${count.warehouse.name_ar} · ${count.count_date.substring(0, 10)}`" :crumbs="[{ label: 'الجرد', href: route('inventory.counts.index') }, { label: count.count_number }]">
            <template #actions v-if="editable">
                <ImportLines kind="stock" label="استيراد الكميات المعدودة" @imported="importCounts" />
                <button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">حفظ الكميات</button>
                <button v-if="$can('inventory.counts.approve')" @click="approve" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">اعتماد الجرد</button>
                <button v-if="$can('inventory.counts.approve')" @click="cancel" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">إلغاء</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-3 gap-3 mb-4">
            <StatCard label="عدد الأصناف" :value="summary.lines" />
            <StatCard label="لم تُجرد بعد" :value="summary.uncounted" tone="amber" />
            <StatCard label="قيمة الفروقات" :value="amount(summary.value_difference)" :tone="summary.value_difference < 0 ? 'rose' : 'emerald'" />
        </div>

        <div class="flex flex-wrap gap-2 mb-3 text-xs">
            <form @submit.prevent="apply" class="flex gap-2">
                <input v-model="filters.search" placeholder="بحث بالاسم / الرمز / الباركود" class="rounded-xl border-slate-200 text-xs w-64" />
                <select v-model="filters.only" class="rounded-xl border-slate-200 text-xs"><option value="">كل الأصناف</option><option value="uncounted">غير المجرودة</option><option value="differences">التي بها فروقات</option></select>
                <button class="px-4 rounded-xl bg-slate-800 text-white font-bold">{{ $t('ui.apply') }}</button>
            </form>
            <div v-if="editable && count.count_type === 'partial'" class="relative">
                <input v-model="lookup" @input="search" placeholder="+ إضافة صنف للجرد" class="rounded-xl border-slate-200 text-xs w-64" />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto">
                    <button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="addProduct(p)" @keydown.enter.prevent="addProduct(p)" class="block w-full text-start px-3 py-2 hover:bg-indigo-50">{{ p.name_ar }} <span class="text-slate-400">{{ p.sku }}</span></button>
                </div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="text-end px-3 py-2">الرصيد الدفتري</th><th class="text-center px-3 py-2 w-40">الكمية المجرودة</th><th class="text-end px-3 py-2">الفرق</th><th class="text-end px-3 py-2">قيمة الفرق</th></tr></thead>
                <tbody>
                    <tr v-for="item in items.data" :key="item.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-semibold">{{ item.product?.name_ar }} <span class="text-slate-400 font-mono">{{ item.product?.sku }}</span></td>
                        <td class="px-3 py-2 text-end font-mono">{{ qty(item.system_quantity) }}</td>
                        <td class="px-3 py-1 text-center">
                            <input v-if="editable" v-model="entered[item.product_id]" type="number" step="0.0001" min="0" class="w-32 rounded-lg border-slate-200 text-xs font-mono text-center" />
                            <span v-else class="font-mono">{{ item.counted_quantity === null ? '—' : qty(item.counted_quantity) }}</span>
                        </td>
                        <td class="px-3 py-2 text-end font-mono" :class="entered[item.product_id] !== '' && Number(entered[item.product_id]) !== Number(item.system_quantity) ? 'text-rose-600 font-bold' : ''">
                            {{ entered[item.product_id] === '' || entered[item.product_id] === null ? '' : qty(Number(entered[item.product_id]) - Number(item.system_quantity)) }}
                        </td>
                        <td class="px-3 py-2 text-end font-mono">{{ entered[item.product_id] === '' || entered[item.product_id] === null ? '' : amount((Number(entered[item.product_id]) - Number(item.system_quantity)) * Number(item.unit_cost)) }}</td>
                    </tr>
                    <tr v-if="!items.data.length"><td colspan="5" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="items" />
        </div>
    </AppLayout>
</template>

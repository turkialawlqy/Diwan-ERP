<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { qty, money } from '@/utils/format';

const props = defineProps({ product: Object, data: Object, warehouses: Array, filters: Object });
const form = reactive({ product_id: props.filters.product_id ?? '', warehouse_id: props.filters.warehouse_id ?? '', start_date: props.filters.start_date, end_date: props.filters.end_date });
const name = ref(props.product?.name_ar ?? '');
const results = ref([]);
const search = async () => { if (name.value.trim().length >= 2) results.value = (await axios.get(route('lookup.products'), { params: { q: name.value } })).data.data; };
const pick = (p) => { form.product_id = p.id; name.value = p.name_ar; results.value = []; };
const apply = () => router.get(route('inventory.reports.item-card'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true });
</script>

<template>
    <AppLayout>
        <Head title="كارت الصنف" />
        <PageHeader title="كارت الصنف (حركة صنف)" :subtitle="product ? `${product.name_ar} · ${product.sku || ''}` : ''" :crumbs="[{ label: 'المستودعات' }, { label: 'كارت الصنف' }]" />

        <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-5 gap-3 text-xs mb-4">
            <div class="relative md:col-span-2">
                <span class="block font-bold text-slate-600 mb-1">الصنف</span>
                <input v-model="name" @input="search" class="w-full rounded-xl border-slate-200 text-xs" placeholder="ابحث عن صنف..." />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto"><button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="pick(p)" @keydown.enter.prevent="pick(p)" class="block w-full text-start px-3 py-2 hover:bg-indigo-50">{{ p.name_ar }}</button></div>
            </div>
            <label><span class="block font-bold text-slate-600 mb-1">المستودع</span><select v-model="form.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الكل</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></label>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.from') }}</span><input v-model="form.start_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <div class="flex items-end gap-2"><input v-model="form.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /><button class="rounded-xl bg-indigo-600 text-white font-bold px-4 py-2">{{ $t('ui.apply') }}</button></div>
        </form>

        <div v-if="data" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">{{ $t('ui.date') }}</th><th class="text-start px-3 py-2">الحركة</th><th class="text-start px-3 py-2">المستودع</th><th class="text-start px-3 py-2">{{ $t('ui.reference') }}</th><th class="text-end px-3 py-2">وارد</th><th class="text-end px-3 py-2">صادر</th><th class="text-end px-3 py-2">التكلفة</th><th class="text-end px-3 py-2">الرصيد</th></tr></thead>
                <tbody>
                    <tr v-if="data.movements.current_page === 1" class="bg-amber-50/50 font-bold"><td class="px-3 py-2" colspan="7">رصيد أول المدة</td><td class="px-3 py-2 text-end font-mono">{{ qty(data.opening_quantity) }}</td></tr>
                    <tr v-for="m in data.movements.data" :key="m.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 text-center font-mono">{{ m.date }}</td><td class="px-3 py-2">{{ m.type }}<span v-if="m.batch" class="text-slate-400"> · {{ m.batch }}</span></td><td class="px-3 py-2">{{ m.warehouse }}</td><td class="px-3 py-2 font-mono text-slate-500">{{ m.reference }}</td>
                        <td class="px-3 py-2 text-end font-mono text-emerald-700">{{ m.in ? qty(m.in) : '' }}</td><td class="px-3 py-2 text-end font-mono text-rose-700">{{ m.out ? qty(m.out) : '' }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ $can('inventory.costs.view') ? money(m.unit_cost) : '—' }}</td><td class="px-3 py-2 text-end font-mono font-bold">{{ qty(m.balance) }}</td>
                    </tr>
                    <tr v-if="!data.movements.data.length"><td colspan="8" class="py-10 text-center text-slate-400">لا توجد حركات</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="data.movements" />
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { money } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';

/**
 * Editable document lines (product search + free text lines) with live
 * totals. Prices come from the customer's price list when `priceRoute` is set.
 */
const props = defineProps({
    modelValue: { type: Array, required: true },
    priceField: { type: String, default: 'selling_price' },
    priceLabel: { type: String, default: 'السعر' },
    customerId: { type: [Number, String, null], default: null },
    priceRoute: { type: String, default: null },
    allowDiscount: { type: Boolean, default: true },
    allowFreeText: { type: Boolean, default: true },
    priceKey: { type: String, default: 'unit_price' },
});
const items = computed(() => props.modelValue);

const term = ref('');
const results = ref([]);
let timer = null;
const search = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (term.value.trim().length < 1) { results.value = []; return; }
        results.value = (await axios.get(route('lookup.products'), { params: { q: term.value } })).data.data;
    }, 250);
};

const add = async (p) => {
    const line = { product_id: p.id, item_name: p.name_ar, quantity: 1, [props.priceKey]: Number(p[props.priceField] ?? 0), discount: 0, tax_rate: Number(p.tax_rate ?? 15) };
    if (props.priceRoute && props.customerId) {
        try {
            const { data } = await axios.get(route(props.priceRoute), { params: { product_id: p.id, customer_id: props.customerId, quantity: 1 } });
            line[props.priceKey] = data.price;
        } catch (e) { /* keep product price */ }
    }
    items.value.push(line);
    results.value = [];
    term.value = '';
};
const addFree = () => items.value.push({ product_id: null, item_name: '', quantity: 1, [props.priceKey]: 0, discount: 0, tax_rate: 15 });

const lineNet = (l) => Math.max(0, Number(l.quantity || 0) * Number(l[props.priceKey] || 0) - Number(l.discount || 0));
const lineTax = (l) => Math.round(lineNet(l) * Number(l.tax_rate ?? 0)) / 100;
const totals = computed(() => {
    const net = items.value.reduce((s, l) => s + Math.round(lineNet(l) * 100) / 100, 0);
    const tax = items.value.reduce((s, l) => s + lineTax(l), 0);
    return { net, tax, total: net + tax };
});
</script>

<template>
    <div>
        <div class="flex gap-2 mb-2">
            <div class="relative flex-1">
                <input v-model="term" @input="search" placeholder="ابحث عن صنف بالاسم أو الرمز أو الباركود..." class="w-full rounded-xl border-slate-200 text-xs" />
                <div v-if="results.length" class="absolute z-20 mt-1 w-full bg-white border rounded-xl shadow max-h-56 overflow-y-auto">
                    <button v-for="p in results" :key="p.id" type="button" @click="add(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">
                        {{ p.name_ar }} <span class="text-slate-400 font-mono">{{ p.sku }}</span>
                    </button>
                </div>
            </div>
            <button v-if="allowFreeText" type="button" @click="addFree" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">+ سطر حر</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs min-w-[640px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="text-start px-2 py-2">الصنف</th>
                        <th class="px-2 py-2 w-24">الكمية</th>
                        <th class="px-2 py-2 w-28">{{ priceLabel }}</th>
                        <th v-if="allowDiscount" class="px-2 py-2 w-24">الخصم</th>
                        <th class="px-2 py-2 w-20">الضريبة %</th>
                        <th class="px-2 py-2 w-28 text-end">الإجمالي</th>
                        <th class="w-8"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in items" :key="i" class="border-t border-slate-100">
                        <td class="px-2 py-1.5"><input v-model="l.item_name" :readonly="!!l.product_id" class="w-full rounded-lg border-slate-200 text-xs" :class="l.product_id ? 'bg-slate-50' : ''" /></td>
                        <td class="px-2 py-1.5"><input v-model="l.quantity" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="px-2 py-1.5"><input v-model="l[priceKey]" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td v-if="allowDiscount" class="px-2 py-1.5"><input v-model="l.discount" type="number" step="0.01" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="px-2 py-1.5"><input v-model="l.tax_rate" type="number" step="0.01" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(lineNet(l) + lineTax(l)) }}</td>
                        <td class="text-center"><button type="button" @click="items.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                    </tr>
                    <tr v-if="!items.length"><td colspan="7" class="py-6 text-center text-slate-400">أضف أصنافاً بالبحث أعلاه</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex justify-end mt-3">
            <dl class="text-xs w-64 space-y-1">
                <div class="flex justify-between"><dt class="text-slate-500">الصافي قبل الضريبة</dt><dd class="font-mono">{{ money(totals.net) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">ضريبة القيمة المضافة</dt><dd class="font-mono">{{ money(totals.tax) }}</dd></div>
                <div class="flex justify-between border-t pt-1 font-black"><dt>الإجمالي</dt><dd class="font-mono">{{ money(totals.total) }}</dd></div>
            </dl>
        </div>
    </div>
</template>

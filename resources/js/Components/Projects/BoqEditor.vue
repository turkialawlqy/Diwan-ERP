<script setup>
import { computed } from 'vue';
import { money } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';

/**
 * Bill of quantities editor: group rows (WBS headers) and priced items.
 * A paste box accepts rows copied from Excel: code, description, unit, qty, rate, cost.
 */
const props = defineProps({ modelValue: { type: Array, required: true } });
const rows = computed(() => props.modelValue);

const addGroup = () => rows.value.push({ code: '', description: '', is_group: true });
const addItem = () => {
    const lastGroup = [...rows.value].reverse().find((r) => r.is_group);
    rows.value.push({ code: '', parent_code: lastGroup?.code || null, description: '', unit: '', quantity: 0, unit_price: 0, unit_cost: 0, is_group: false });
};
// Excel copies may carry Arabic-Indic digits and thousands separators.
const num = (v) => Number(String(v ?? '').trim()
    .replace(/[٠-٩]/g, (d) => d.charCodeAt(0) - 0x0660)
    .replace(/[۰-۹]/g, (d) => d.charCodeAt(0) - 0x06F0)
    .replace(/٫/g, '.')
    .replace(/[,٬\s]/g, '')) || 0;
const paste = (event) => {
    const text = event.clipboardData.getData('text');
    if (!text.includes('\t')) return;
    event.preventDefault();
    const lastGroup = [...rows.value].reverse().find((r) => r.is_group);
    text.trim().split(/\r?\n/).forEach((line) => {
        const [code, description, unit, quantity, unitPrice, unitCost] = line.split('\t');
        rows.value.push({ code: code?.trim(), parent_code: lastGroup?.code || null, description: description?.trim(), unit: unit?.trim(), quantity: num(quantity), unit_price: num(unitPrice), unit_cost: num(unitCost), is_group: false });
    });
};
const total = computed(() => rows.value.filter((r) => !r.is_group).reduce((s, r) => s + Number(r.quantity || 0) * Number(r.unit_price || 0), 0));
const cost = computed(() => rows.value.filter((r) => !r.is_group).reduce((s, r) => s + Number(r.quantity || 0) * Number(r.unit_cost || 0), 0));
</script>

<template>
    <div>
        <div class="flex flex-wrap gap-2 mb-2">
            <button type="button" @click="addGroup" class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold">+ مجموعة (WBS)</button>
            <button type="button" @click="addItem" class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold">+ بند</button>
            <input @paste="paste" placeholder="الصق صفوفاً من Excel هنا: الرمز، الوصف، الوحدة، الكمية، سعر الوحدة، التكلفة التقديرية" class="flex-1 min-w-64 rounded-xl border-dashed border-slate-300 text-xs" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs min-w-[860px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-2 py-2 w-24 text-start">الرمز</th><th class="px-2 py-2 text-start">الوصف</th><th class="px-2 py-2 w-20">الوحدة</th><th class="px-2 py-2 w-24">الكمية</th><th class="px-2 py-2 w-28">سعر الوحدة</th><th class="px-2 py-2 w-28">التكلفة التقديرية</th><th class="px-2 py-2 w-28 text-end">القيمة</th><th class="w-8"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i" class="border-t border-slate-100" :class="r.is_group ? 'bg-slate-50 font-bold' : ''">
                        <td class="px-2 py-1"><input v-model="r.code" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="px-2 py-1"><input v-model="r.description" class="w-full rounded-lg border-slate-200 text-xs" :class="r.is_group ? 'font-bold' : 'ps-4'" /></td>
                        <template v-if="!r.is_group">
                            <td class="px-2 py-1"><input v-model="r.unit" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                            <td class="px-2 py-1"><input v-model="r.quantity" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1"><input v-model="r.unit_price" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1"><input v-model="r.unit_cost" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                            <td class="px-2 py-1 text-end font-mono">{{ money(Number(r.quantity) * Number(r.unit_price)) }}</td>
                        </template>
                        <td v-else colspan="5"></td>
                        <td class="text-center"><button type="button" @click="rows.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="8" class="py-6 text-center text-slate-400">أضف بنود جدول الكميات أو الصقها من Excel</td></tr>
                </tbody>
                <tfoot class="bg-slate-50 font-bold">
                    <tr><td colspan="5" class="px-2 py-2 text-end">التكلفة التقديرية: <span class="font-mono">{{ money(cost) }}</span></td><td class="px-2 py-2 text-end">قيمة العقد</td><td class="px-2 py-2 text-end font-mono">{{ money(total) }}</td><td></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>

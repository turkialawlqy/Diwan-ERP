<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import ImportLines from '@/Components/Documents/ImportLines.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { dimensionRequired } from '@/utils/dimensions';
import { PlusIcon, TrashIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
import { today } from '@/utils/format';

const props = defineProps({
    accounts: Array,
    costCenters: Array,
    projects: { type: Array, default: () => [] },
    dimensionPolicy: { type: Object, default: () => ({}) },
    customers: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    partyAccounts: { type: Object, default: () => ({}) },
    bankAccounts: { type: Array, default: () => [] },
});

// Receivable / payable lines name their customer or supplier.
const auxOptions = (line) => {
    const type = partyType(line);
    if (type === 'customer') return props.customers;
    if (type === 'supplier') return props.suppliers;
    return props.bankAccounts.filter((b) => b.account_id === line.account_id);
};
const auxLabel = (o) => (o.code ? `${o.name} (${o.code})` : o.name);
const supplierLabel = (o) => o.name;
// Customers and suppliers are long lists: searched on the server. Inactive customers stay selectable here.
const auxSearch = (line) => {
    const type = partyType(line);
    if (type === 'customer') return { route: 'lookup.customers', params: { any_status: 1 } };
    return type === 'supplier' ? 'lookup.suppliers' : null;
};
const partyType = (line) => props.partyAccounts?.[String(line.account_id)] || null;
const syncParty = (line) => {
    const type = partyType(line);
    if (line.party_type !== type) {
        line.party_type = type || '';
        line.party_id = '';
        // A bank / cash box ledger account with a single box behind it picks that box.
        if (type === 'bank') {
            const boxes = auxOptions(line);
            if (boxes.length === 1) line.party_id = boxes[0].id;
        }
    }
};

const accountOf = (line) => props.accounts?.find((a) => a.id === line.account_id);
const needs = (line, dimension) => dimensionRequired(props.dimensionPolicy, dimension, accountOf(line));

const blankLine = (i = 0) => ({ account_id: props.accounts?.[i]?.id || '', cost_center_id: '', project_id: '', party_type: '', party_id: '', description: '', debit: 0, credit: 0 });

const form = useForm({
    date: today(),
    reference: '',
    description: '',
    entry_type: 'manual',
    save_as: 'posted',
    lines: [blankLine(0), blankLine(1)],
});
useUnsavedGuard(form);

const addLine = () => form.lines.push(blankLine(0));
const removeLine = (index) => {
    if (form.lines.length > 2) form.lines.splice(index, 1);
};

const totalDebit = computed(() => form.lines.reduce((acc, l) => acc + (parseFloat(l.debit) || 0), 0));
const totalCredit = computed(() => form.lines.reduce((acc, l) => acc + (parseFloat(l.credit) || 0), 0));
const isBalanced = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 0.01 && totalDebit.value > 0);

const submitEntry = (saveAs = 'posted') => {
    form.lines.forEach(syncParty);
    if (!isBalanced.value) return;
    form.save_as = saveAs;
    form.post(route('accounting.journals.store'));
};

const formatCurrency = (val) => Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const importLines = (lines, replace) => {
    const rows = lines.map((l) => ({ account_id: l.account_id, cost_center_id: l.cost_center_id || '', project_id: '', party_type: '', party_id: '', description: l.description || '', debit: l.debit, credit: l.credit }));
    form.lines = replace ? rows : [...form.lines.filter((l) => Number(l.debit) || Number(l.credit)), ...rows];
};
</script>

<template>
    <AppLayout own-errors>
        <Head title="قيد يومية جديد" />
        <PageHeader title="قيد يومية جديد" :crumbs="[{ label: 'المحاسبة' }, { label: 'القيود اليومية', href: route('accounting.journals.index') }, { label: 'جديد' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
                    <form @submit.prevent="submitEntry('posted')" class="space-y-4 text-xs ">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">تاريخ القيد <span class="text-rose-500">*</span></label>
                                <DatePicker 
                                    v-model="form.date" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الرقم المرجعي (اختياري)</label>
                                <input 
                                    v-model="form.reference" 
                                    type="text" 
                                    placeholder="رقم شيك، إشعار، عقد..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>
            
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">نوع القيد</label>
                            <select v-model="form.entry_type" class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl">
                                <option value="manual">قيد يومية عادي</option>
                                <option value="opening">قيد أرصدة افتتاحية</option>
                                <option value="adjustment">قيد تسوية</option>
                            </select>
                        </div>
            
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">بيان القيد العام <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.description" 
                                type="text" 
                                required 
                                placeholder="شرح القيد المحاسبي..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>
            
                        <!-- Lines Table -->
                        <div class="border border-slate-200 rounded-xl overflow-hidden">
                            <table class="w-full text-3xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-600 border-b border-slate-200 font-bold">
                                        <th class="py-2.5 px-3 text-start">الحساب المحاسبي</th>
                                        <th class="py-2.5 px-3 text-start">الحساب المساعد</th>
                                        <th class="py-2.5 px-3 text-start">مركز التكلفة</th>
                                        <th class="py-2.5 px-3 text-start">المشروع</th>
                                        <th class="py-2.5 px-3 text-start">شرح البند</th>
                                        <th class="py-2.5 px-3 text-end w-28">مدين (Debit)</th>
                                        <th class="py-2.5 px-3 text-end w-28">دائن (Credit)</th>
                                        <th class="py-2.5 px-2 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(line, idx) in form.lines" :key="idx" class="bg-white">
                                        <td class="p-2">
                                            <SelectCreate v-model="line.account_id" :options="accounts" entity="account" :label="(acc) => `${acc.code} - ${acc.name_ar}`" placeholder="اختر الحساب" :allow-empty="false" required @update:modelValue="$nextTick(() => syncParty(line))" />
                                        </td>
                                        <td class="p-2">
                                            <SelectCreate v-if="partyType(line)" v-model="line.party_id" :options="auxOptions(line)" :search="auxSearch(line)" :entity="partyType(line) === 'bank' ? '' : partyType(line)" :label="partyType(line) === 'supplier' ? supplierLabel : auxLabel" :placeholder="partyType(line) === 'customer' ? 'اختر العميل' : partyType(line) === 'supplier' ? 'اختر المورد' : 'اختر الحساب المساعد'" :allow-empty="partyType(line) === 'bank'" :required="partyType(line) !== 'bank'" />
                                            <span v-else class="text-slate-300 px-2">—</span>
                                        </td>
                                        <td class="p-2">
                                            <SelectCreate v-model="line.cost_center_id" :options="costCenters" entity="cost_center" :label="(cc) => `${cc.code} - ${cc.name_ar}`" :placeholder="needs(line, 'cost_center') ? 'مطلوب *' : '—'" :allow-empty="!needs(line, 'cost_center')" />
                                        </td>
                                        <td class="p-2">
                                            <select v-model="line.project_id" class="w-full py-1 ps-2 pe-8 bg-slate-50 border rounded-lg text-3xs" :class="needs(line, 'project') && !line.project_id ? 'border-rose-300' : 'border-slate-200'">
                                                <option value="">{{ needs(line, 'project') ? 'مطلوب *' : '—' }}</option>
                                                <option v-for="pr in projects" :key="pr.id" :value="pr.id">{{ pr.name }}</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            <input 
                                                v-model="line.description" 
                                                type="text" 
                                                placeholder="بيان تفصيلي"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs"
                                            />
                                        </td>
                                        <td class="p-2">
                                            <input 
                                                v-model.number="line.debit" 
                                                type="text" inputmode="decimal" data-num 
                                                step="0.01" 
                                                min="0"
                                                @focus="line.credit = 0"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs font-mono text-end font-bold"
                                            />
                                        </td>
                                        <td class="p-2">
                                            <input 
                                                v-model.number="line.credit" 
                                                type="text" inputmode="decimal" data-num 
                                                step="0.01" 
                                                min="0"
                                                @focus="line.debit = 0"
                                                class="w-full py-1 px-2 bg-slate-50 border border-slate-200 rounded-lg text-3xs font-mono text-end font-bold"
                                            />
                                        </td>
                                        <td class="p-2 text-center">
                                            <button 
                                                type="button" 
                                                @click="removeLine(idx)" 
                                                :disabled="form.lines.length <= 2"
                                                class="p-1 text-slate-300 hover:text-rose-600 disabled:opacity-20"
                                            >
                                                <TrashIcon class="w-3.5 h-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 font-bold border-t border-slate-200">
                                        <td colspan="3" class="p-2 text-start">
                                            <ImportLines kind="journal" label="استيراد الأطراف من Excel" @imported="importLines" />
                                            <button 
                                                type="button" 
                                                @click="addLine" 
                                                class="text-indigo-600 hover:text-indigo-800 font-bold flex items-center gap-1"
                                            >
                                                <PlusIcon class="w-3.5 h-3.5" />
                                                <span>إضافة طرف قيد</span>
                                            </button>
                                        </td>
                                        <td class="p-2 text-end font-mono font-black text-xs text-slate-900">
                                            {{ formatCurrency(totalDebit) }} ﷼
                                        </td>
                                        <td class="p-2 text-end font-mono font-black text-xs text-slate-900">
                                            {{ formatCurrency(totalCredit) }} ﷼
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
            
                        <div v-if="Object.keys(form.errors).length" class="p-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs space-y-1">
                            <p v-for="(msg, key) in form.errors" :key="key">{{ msg }}</p>
                        </div>
            
                        <!-- Balance Verification Status -->
                        <div 
                            :class="[
                                isBalanced ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-amber-50 border-amber-200 text-amber-800',
                                'p-3 rounded-xl border flex items-center justify-between text-xs'
                            ]"
                        >
                            <div class="flex items-center gap-2">
                                <CheckCircleIcon v-if="isBalanced" class="w-5 h-5 text-emerald-600" />
                                <ExclamationTriangleIcon v-else class="w-5 h-5 text-amber-600" />
                                <span v-if="isBalanced" class="font-bold">القيد متوازن وجاهز للترحيل مباشرة.</span>
                                <span v-else class="font-bold">القيد غير متوازن! الفرق بين المدين والدائن: {{ formatCurrency(Math.abs(totalDebit - totalCredit)) }} ﷼</span>
                            </div>
                        </div>
            
                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                            <Link :href="route('accounting.journals.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
                            <button
                                type="button"
                                @click="submitEntry('draft')"
                                :disabled="!isBalanced || form.processing"
                                class="px-4 py-2 border border-amber-300 text-amber-700 rounded-xl font-bold disabled:opacity-50"
                            >
                                حفظ كمسودة
                            </button>
                            <button 
                                v-if="$can('accounting.journals.post')"
                                type="submit" 
                                :disabled="!isBalanced || form.processing"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                            >
                                ترحيل القيد اليومي
                            </button>
                        </div>
                    </form>
        </div>
    </AppLayout>
</template>

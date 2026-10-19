<script setup>
import { ask } from '@/composables/useDialog';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PrinterIcon,
    ArrowRightIcon,
    BookOpenIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    entry: Object,
});

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const printVoucher = () => {
    window.print();
};

const statusLabels = { draft: 'مسودة (غير مرحّل)', posted: 'مرحّل ومعتمد', reversed: 'معكوس' };
const statusClass = { draft: 'text-amber-600', posted: 'text-emerald-600', reversed: 'text-rose-600' };
const manualTypes = ['manual', 'opening', 'adjustment', 'recurring'];

const postEntry = () => router.post(route('accounting.journals.post', props.entry.id), {}, { preserveScroll: true });
const reverseEntry = async () => {
    const reason = await ask('يُنشأ قيد عكسي بنفس المبالغ. سبب العكس (اختياري):', { title: 'عكس القيد', type: 'textarea', confirmText: 'عكس القيد', danger: true });
    if (reason === null) return;
    router.post(route('accounting.journals.reverse', props.entry.id), { reason });
};
const deleteDraft = () => {
    if (confirm('حذف مسودة القيد؟')) router.delete(route('accounting.journals.destroy', props.entry.id));
};
</script>

<template>
    <AppLayout>
        <Head :title="`سند قيد يومية ${entry.entry_number}`" />

        <div class="max-w-4xl mx-auto space-y-4">
            <!-- Action Bar (Hidden when printing) -->
            <div class="flex items-center justify-between no-print">
                <Link 
                    :href="route('accounting.journals.index')"
                    class="px-3.5 py-1.5 bg-white border border-slate-200 text-slate-600 rounded-xl text-xs font-semibold hover:bg-slate-50 transition flex items-center gap-1.5"
                >
                    <ArrowRightIcon class="w-3.5 h-3.5" />
                    <span>العودة للقيود اليومية</span>
                </Link>

                <div class="flex items-center gap-2">
                <button v-if="entry.status === 'draft' && $can('accounting.journals.post')" @click="postEntry" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold">ترحيل القيد</button>
                <button v-if="entry.status === 'draft' && $can('accounting.journals.delete')" @click="deleteDraft" class="px-4 py-2 bg-white border border-rose-200 text-rose-600 rounded-xl text-xs font-bold">حذف المسودة</button>
                <button v-if="entry.status === 'posted' && manualTypes.includes(entry.entry_type) && $can('accounting.journals.cancel')" @click="reverseEntry" class="px-4 py-2 bg-white border border-amber-300 text-amber-700 rounded-xl text-xs font-bold">عكس القيد</button>
                <button 
                    @click="printVoucher"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-1.5"
                >
                    <PrinterIcon class="w-4 h-4 stroke-2" />
                    <span>طباعة سند القيد</span>
                </button>
                </div>
            </div>

            <!-- Official Journal Voucher Sheet (Print Optimized) -->
            <div class="bg-white rounded-3xl p-8 sm:p-12 border border-slate-200/80 shadow-sm print:border-none print:shadow-none print:p-0 space-y-6">
                <!-- Header -->
                <div class="flex items-start justify-between border-b border-slate-100 pb-6">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <BookOpenIcon class="w-6 h-6 text-indigo-600" />
                            <h1 class="text-lg font-black text-slate-900">سند قيد يومية عامة (JOURNAL VOUCHER)</h1>
                        </div>
                        <p class="text-xs text-slate-500">{{ $page.props.brand?.name }} لإدارة الأعمال والمحاسبة المالية</p>
                    </div>

                    <div class="text-end font-mono">
                        <span class="text-3xs text-slate-400 block">رقم القيد</span>
                        <span class="text-base font-black text-indigo-600">{{ entry.entry_number }}</span>
                    </div>
                </div>

                <!-- Meta Details -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-slate-50/70 rounded-2xl text-xs">
                    <div>
                        <span class="text-3xs text-slate-400 block mb-0.5">تاريخ القيد:</span>
                        <span class="font-mono font-bold text-slate-800">{{ entry.date ? entry.date.substring(0, 10) : '-' }}</span>
                    </div>
                    <div>
                        <span class="text-3xs text-slate-400 block mb-0.5">الرقم المرجعي:</span>
                        <span class="font-mono font-semibold text-slate-800">{{ entry.reference || 'لا يوجد' }}</span>
                    </div>
                    <div>
                        <span class="text-3xs text-slate-400 block mb-0.5">الحالة:</span>
                        <span :class="[statusClass[entry.status], 'font-bold']">{{ statusLabels[entry.status] || entry.status }}</span>
                        <span v-if="entry.reversed_by" class="block text-[10px] text-slate-500">بالقيد <Link :href="route('accounting.journals.show', entry.reversed_by.id)" class="text-indigo-600">{{ entry.reversed_by.entry_number }}</Link></span>
                        <span v-if="entry.reversal_of" class="block text-[10px] text-slate-500">عكس القيد <Link :href="route('accounting.journals.show', entry.reversal_of.id)" class="text-indigo-600">{{ entry.reversal_of.entry_number }}</Link></span>
                    </div>
                    <div>
                        <span class="text-3xs text-slate-400 block mb-0.5">المسؤول:</span>
                        <span class="font-semibold text-slate-800">{{ entry.creator?.name || 'مدير النظام' }}</span>
                    </div>
                </div>

                <!-- Description -->
                <div class="p-3 bg-indigo-50/40 border border-indigo-100/60 rounded-xl text-xs">
                    <span class="text-3xs font-bold text-indigo-900 block mb-1">بيان وشرح القيد:</span>
                    <p class="text-slate-800 font-medium leading-relaxed">{{ entry.description }}</p>
                </div>

                <!-- Lines Table -->
                <div class="border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold">
                                <th class="py-3 px-4 text-start">كود الحساب</th>
                                <th class="py-3 px-4 text-start">اسم الحساب في الدليل</th>
                                <th class="py-3 px-4 text-start">البيان التفصيلي</th>
                                <th class="py-3 px-4 text-start">مركز التكلفة</th>
                                <th class="py-3 px-4 text-end">مدين (Debit)</th>
                                <th class="py-3 px-4 text-end">دائن (Credit)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="line in entry.lines" :key="line.id" class="text-slate-800">
                                <td class="py-3 px-4 font-mono font-bold text-indigo-600">
                                    {{ line.account?.code || '-' }}
                                </td>
                                <td class="py-3 px-4 font-semibold text-slate-900">
                                    {{ line.account?.name_ar || '-' }}
                                </td>
                                <td class="py-3 px-4 text-slate-600 text-3xs">
                                    {{ line.description }}
                                </td>
                                <td class="py-3 px-4 text-slate-500 text-3xs">{{ line.cost_center?.name_ar || '—' }}</td>
                                <td class="py-3 px-4 font-mono font-bold text-end">
                                    {{ line.debit > 0 ? formatCurrency(line.debit) + ' ﷼' : '-' }}
                                </td>
                                <td class="py-3 px-4 font-mono font-bold text-end">
                                    {{ line.credit > 0 ? formatCurrency(line.credit) + ' ﷼' : '-' }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-100/80 font-black border-t border-slate-200 text-slate-900">
                                <td colspan="4" class="py-3 px-4 text-start">المجموع الإجمالي للقيد:</td>
                                <td class="py-3 px-4 font-mono text-end text-sm text-indigo-600">
                                    {{ formatCurrency(entry.total_debit) }} ﷼
                                </td>
                                <td class="py-3 px-4 font-mono text-end text-sm text-indigo-600">
                                    {{ formatCurrency(entry.total_credit) }} ﷼
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures -->
                <div class="grid grid-cols-3 gap-6 pt-12 text-center text-xs text-slate-600">
                    <div class="space-y-10 border-t border-slate-200 pt-3">
                        <span class="font-bold">إعداد المحاسب</span>
                    </div>
                    <div class="space-y-10 border-t border-slate-200 pt-3">
                        <span class="font-bold">مراجعة وتدقيق</span>
                    </div>
                    <div class="space-y-10 border-t border-slate-200 pt-3">
                        <span class="font-bold">المدير المالي / الاعتماد</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    .no-print {
        display: none !important;
    }
}
</style>

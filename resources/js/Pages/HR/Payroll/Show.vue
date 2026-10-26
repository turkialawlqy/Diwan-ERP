<script setup>
import { confirmAction } from '@/composables/useDialog';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money } from '@/utils/format';

const props = defineProps({ payroll: Object, adjustments: Array, employees: Array, bankAccounts: Array, errors: { type: Object, default: () => ({}) } });
const act = (name, message) => { if (!message || confirm(message)) router.post(route(name, props.payroll.id), {}, { preserveScroll: true }); };
const adjForm = useForm({ employee_id: '', type: 'bonus', amount: 0, description: '' });
const payOpen = ref(false);
const unapprove = async () => {
    if (await confirmAction('يُعكس قيد الاستحقاق وتُعاد أقساط السلف ويعود المسير مسودة قابلة للتعديل. متابعة؟', { title: 'إلغاء اعتماد المسير', danger: true, confirmText: 'إلغاء الاعتماد' })) {
        router.post(route('hr.payroll.unapprove', props.payroll.id), {}, { preserveScroll: true });
    }
};
const payForm = useForm({ date: new Date().toISOString().substring(0, 10), bank_account_id: '' });
const typeLabel = { bonus: 'مكافأة', commission: 'عمولة', overtime_hours: 'ساعات إضافي', penalty: 'جزاء', deduction: 'استقطاع' };
const sum = (key) => props.payroll.items.reduce((s, i) => s + Number(i[key] || 0), 0);
const remove = async (url, message) => { if (await confirmAction(message, { danger: true, confirmText: 'حذف' })) router.delete(url, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="`مسير ${payroll.payroll_number}`" />
        <PageHeader :title="`مسير رواتب ${payroll.month}/${payroll.year}`" :subtitle="payroll.payroll_number" :crumbs="[{ label: 'الرواتب', href: route('hr.payroll.index') }, { label: payroll.payroll_number }]">
            <template #actions>
                <StatusBadge :status="payroll.status" />
                <template v-if="payroll.status === 'draft'">
                    <button v-if="$can('hr.payroll.create')" @click="act('hr.payroll.recalculate')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">إعادة احتساب</button>
                    <button v-if="$can('hr.payroll.approve')" @click="act('hr.payroll.approve', 'اعتماد المسير وقيده محاسبياً؟')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">اعتماد</button>
                </template>
                <button v-if="payroll.status === 'approved' && $can('hr.payroll.approve')" @click="unapprove" class="px-3 py-2 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">إلغاء الاعتماد</button>
                <button v-if="payroll.status === 'approved' && $can('hr.payroll.post')" @click="payOpen = true" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">صرف الرواتب</button>
                <template v-if="$can('hr.payroll.export')">
                    <a :href="route('hr.payroll.wps', { payroll: payroll.id, format: 'mudad' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">ملف مُدد (WPS)</a>
                    <a :href="route('hr.payroll.wps', { payroll: payroll.id, format: 'bank' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">ملف البنك</a>
                </template>
            </template>
        </PageHeader>

        <div v-if="errors.wps" class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-700">
            <p class="font-bold mb-1">لا يمكن إنشاء ملف الرواتب؛ صحّح بيانات الموظفين التالية ثم أعد المحاولة:</p>
            <ul class="list-disc ps-5 space-y-0.5"><li v-for="(e, i) in String(errors.wps).split('\n')" :key="i">{{ e }}</li></ul>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-4">
            <table class="w-full text-xs min-w-[1100px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-2 py-2">الموظف</th><th class="px-2 py-2">أيام</th><th class="px-2 py-2 text-end">الأساسي</th><th class="px-2 py-2 text-end">البدلات</th><th class="px-2 py-2 text-end">إضافي</th><th class="px-2 py-2 text-end">مكافآت</th><th class="px-2 py-2 text-end">غياب</th><th class="px-2 py-2 text-end">الإجمالي</th><th class="px-2 py-2 text-end">تأمينات</th><th class="px-2 py-2 text-end">سلف</th><th class="px-2 py-2 text-end">جزاءات</th><th class="px-2 py-2 text-end">الصافي</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="i in payroll.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-2 py-1.5">{{ i.employee?.name }} <span class="text-slate-400 font-mono">{{ i.employee?.employee_number }}</span></td>
                        <td class="px-2 py-1.5 text-center">{{ i.days_worked }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.basic_salary) }}</td><td class="px-2 py-1.5 text-end font-mono">{{ money(i.allowances) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.overtime) }}</td><td class="px-2 py-1.5 text-end font-mono">{{ money(i.bonuses) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono text-rose-600">{{ Number(i.absence_deduction) ? money(i.absence_deduction) : '' }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.gross_salary) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono" :title="`حصة المنشأة ${money(i.gosi_employer)}`">{{ money(i.gosi_employee) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ Number(i.loan_deduction) ? money(i.loan_deduction) : '' }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ Number(i.other_deductions) ? money(i.other_deductions) : '' }}</td>
                        <td class="px-2 py-1.5 text-end font-mono font-bold">{{ money(i.net_salary) }}</td>
                        <td class="px-2 py-1.5 text-end"><Link :href="route('hr.payroll.payslip', [payroll.id, i.id])" class="text-indigo-600">قسيمة</Link></td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50 font-bold">
                    <tr><td class="px-2 py-2" colspan="2">الإجمالي</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('basic_salary')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('allowances')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('overtime')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('bonuses')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('absence_deduction')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('gross_salary')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(payroll.total_gosi_employee) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(payroll.total_loans) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(sum('other_deductions')) }}</td><td class="px-2 py-2 text-end font-mono">{{ money(payroll.net_total) }}</td><td></td></tr>
                </tfoot>
            </table>
            <p class="p-3 text-xs text-slate-500">حصة المنشأة في التأمينات: <b class="font-mono">{{ money(payroll.total_gosi_employer) }}</b> — إجمالي سداد التأمينات: <b class="font-mono">{{ money(Number(payroll.total_gosi_employee) + Number(payroll.total_gosi_employer)) }}</b></p>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
            <h3 class="font-black text-slate-700 mb-2">تعديلات الشهر (مكافآت، عمولات، ساعات إضافي، جزاءات)</h3>
            <div v-for="a in adjustments" :key="a.id" class="flex justify-between py-1 border-b border-slate-50">
                <span>{{ a.employee?.name }} — {{ typeLabel[a.type] }} <span class="text-slate-400">{{ a.description }}</span></span>
                <span><b class="font-mono">{{ a.type === 'overtime_hours' ? a.amount + ' ساعة' : money(a.amount) }}</b> <button v-if="payroll.status === 'draft' && $can('hr.payroll.create')" @click="remove(route('hr.payroll.adjustments.destroy', [payroll.id, a.id]), 'حذف هذا التعديل وإعادة احتساب المسير؟')" class="text-rose-600 ms-2">✕</button></span>
            </div>
            <div v-if="payroll.status === 'draft' && $can('hr.payroll.create')" class="grid grid-cols-6 gap-2 mt-3">
                <select v-model="adjForm.employee_id" class="col-span-2 rounded-xl border-slate-200 text-xs"><option value="">الموظف...</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
                <select v-model="adjForm.type" class="rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in typeLabel" :key="k" :value="k">{{ l }}</option></select>
                <input v-model="adjForm.amount" type="number" step="0.01" class="rounded-xl border-slate-200 text-xs font-mono" />
                <input v-model="adjForm.description" placeholder="ملاحظة" class="rounded-xl border-slate-200 text-xs" />
                <button @click="adjForm.post(route('hr.payroll.adjustments.store', payroll.id), { preserveScroll: true, onSuccess: () => adjForm.reset('amount', 'description') })" class="rounded-xl bg-indigo-600 text-white font-bold">إضافة</button>
            </div>
            <p v-for="(msg, key) in adjForm.errors" :key="key" class="text-rose-600 mt-1">{{ msg }}</p>
        </div>

        <Dialog :show="payOpen" title="صرف الرواتب" @close="payOpen = false">
            <div class="grid grid-cols-2 gap-3">
                <Field label="تاريخ الصرف"><input v-model="payForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الحساب البنكي"><select v-model="payForm.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <p class="text-xs mt-2">المبلغ: <b class="font-mono">{{ money(payroll.net_total) }}</b></p>
            <template #footer><button @click="payForm.post(route('hr.payroll.pay', payroll.id), { onSuccess: () => (payOpen = false) })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">تأكيد الصرف</button></template>
        </Dialog>
    </AppLayout>
</template>

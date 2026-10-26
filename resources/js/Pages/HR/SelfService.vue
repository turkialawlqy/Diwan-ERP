<script setup>
import { confirmAction } from '@/composables/useDialog';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, hijri } from '@/utils/format';

const props = defineProps({ employee: Object, leaveBalance: Object, leaveRequests: Array, loans: Array, payslips: Array, leaveTypes: Array });
const withdraw = async (name, id, message) => {
    if (await confirmAction(message, { confirmText: 'سحب الطلب', danger: true })) router.post(route(name, id), {}, { preserveScroll: true });
};
const leave = useForm({ leave_type_id: props.leaveTypes?.[0]?.id || '', start_date: '', end_date: '', reason: '' });
const loan = useForm({ amount: 0, monthly_installment: 0, reason: '' });
</script>

<template>
    <AppLayout>
        <Head title="خدماتي" />
        <div v-if="!employee" class="max-w-xl mx-auto mt-10 bg-white border border-slate-200 rounded-2xl p-8 text-center space-y-2">
            <h2 class="text-sm font-black text-slate-800">حسابك غير مرتبط بملف موظف</h2>
            <p class="text-xs text-slate-500">الخدمة الذاتية (الإجازات، السلف، قسائم الرواتب) متاحة بعد ربط حسابك بملف موظف. تواصل مع الموارد البشرية لربط المستخدم من شاشة ملف الموظف.</p>
        </div>
        <template v-else>
        <PageHeader :title="`مرحباً ${employee.name}`" :subtitle="`${employee.job_title} · ${employee.department || ''}`" :crumbs="[{ label: 'الخدمة الذاتية' }]" />
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="رصيد الإجازة السنوية" :value="`${leaveBalance.balance} يوم`" tone="indigo" />
            <StatCard label="الرقم الوظيفي" :value="employee.employee_number || '—'" />
            <StatCard label="تاريخ المباشرة" :value="date(employee.hire_date)" />
            <StatCard label="انتهاء الهوية/الإقامة" :value="employee.id_expiry ? `${date(employee.id_expiry)} — ${hijri(employee.id_expiry)}` : '—'" />
        </div>
        <div class="grid lg:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <h3 class="font-black text-slate-700">طلب إجازة</h3>
                <select v-model="leave.leave_type_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                <div class="grid grid-cols-2 gap-2"><input v-model="leave.start_date" type="date" class="rounded-xl border-slate-200 text-xs" /><input v-model="leave.end_date" type="date" class="rounded-xl border-slate-200 text-xs" /></div>
                <input v-model="leave.reason" placeholder="السبب" class="w-full rounded-xl border-slate-200 text-xs" />
                <p v-for="(m, k) in leave.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="leave.post(route('hr.me.leaves'), { preserveScroll: true, onSuccess: () => leave.reset('start_date', 'end_date', 'reason') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إرسال</button>
                <h3 class="font-black text-slate-700 pt-3">طلب سلفة</h3>
                <div class="grid grid-cols-2 gap-2"><label>المبلغ<input v-model="loan.amount" type="number" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label><label>القسط<input v-model="loan.monthly_installment" type="number" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label></div>
                <p v-for="(m, k) in loan.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="loan.post(route('hr.me.loans'), { preserveScroll: true, onSuccess: () => loan.reset() })" class="w-full px-3 py-2 rounded-xl border border-slate-200 font-bold">إرسال طلب السلفة</button>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">طلباتي</h3>
                <div v-for="r in leaveRequests" :key="r.id" class="py-1 border-b border-slate-50">
                    <div class="flex justify-between items-center gap-2"><span>{{ r.type?.name }} <span class="font-mono text-slate-400">{{ date(r.start_date) }} → {{ date(r.end_date) }}</span></span>
                        <span class="flex items-center gap-2"><button v-if="r.status === 'pending'" @click="withdraw('hr.me.leaves.cancel', r.id, 'سحب طلب الإجازة؟')" class="text-rose-600 font-bold">سحب</button><StatusBadge :status="r.status" /></span></div>
                    <p v-if="r.decision_note" class="text-slate-500 mt-0.5">ملاحظة المعتمد: {{ r.decision_note }}</p>
                </div>
                <div v-for="l in loans" :key="'l' + l.id" class="py-1 border-b border-slate-50">
                    <div class="flex justify-between items-center gap-2"><span>سلفة {{ money(l.amount) }} <span class="text-slate-400">مسدد {{ money(l.repaid) }}</span></span>
                        <span class="flex items-center gap-2"><button v-if="l.status === 'pending'" @click="withdraw('hr.me.loans.cancel', l.id, 'سحب طلب السلفة؟')" class="text-rose-600 font-bold">سحب</button><StatusBadge :status="l.status" /></span></div>
                    <p v-if="l.decision_note" class="text-slate-500 mt-0.5">ملاحظة المعتمد: {{ l.decision_note }}</p>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">قسائم الراتب</h3>
                <div v-for="p in payslips" :key="p.id" class="flex justify-between py-1 border-b border-slate-50"><span class="font-mono">{{ p.payroll.month }}/{{ p.payroll.year }}</span><span><b class="font-mono">{{ money(p.net_salary) }}</b> <Link :href="route('hr.me.payslip', p.id)" class="text-indigo-600 ms-2">عرض</Link></span></div>
                <p v-if="!payslips.length" class="text-slate-400">لا توجد قسائم بعد</p>
            </div>
        </div>
        </template>
    </AppLayout>
</template>

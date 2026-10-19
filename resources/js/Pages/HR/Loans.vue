<script setup>
import { ask } from '@/composables/useDialog';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date } from '@/utils/format';

defineProps({ loans: Object, employees: Array, bankAccounts: Array });
const form = useForm({ employee_id: '', date: new Date().toISOString().substring(0, 10), amount: 0, monthly_installment: 0, first_deduction_month: '', reason: '' });
const bank = ref('');
const decide = async (loan, approve) => {
    let note = null;
    if (!approve) {
        note = await ask('سبب الرفض (يظهر للموظف):', { title: 'رفض طلب السلفة', type: 'textarea', confirmText: 'رفض', danger: true });
        if (note === null) return;
    }
    router.post(route('hr.loans.decide', loan.id), { approve, note, bank_account_id: bank.value || null }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head title="السلف" />
        <PageHeader title="سلف الموظفين" subtitle="تُصرف عند الاعتماد وتُستقطع أقساطها من المسير (بحد أقصى نصف الأجر)" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'السلف' }]" />
        <div class="grid lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="p-3 border-b border-slate-100 flex items-center gap-2">صرف من: <select v-model="bank" class="rounded-xl border-slate-200 text-xs"><option value="">الحساب الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الموظف</th><th class="px-3 py-2">التاريخ</th><th class="px-3 py-2 text-end">المبلغ</th><th class="px-3 py-2 text-end">القسط</th><th class="px-3 py-2 text-end">المسدد</th><th class="px-3 py-2">الحالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="l in loans.data" :key="l.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ l.employee?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ date(l.date) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.amount) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.monthly_installment) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.repaid) }}</td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="l.status" /></td>
                            <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="l.status === 'pending' && $can('hr.loans.approve')"><button @click="decide(l, true)" class="text-emerald-600 font-bold me-2">اعتماد وصرف</button><button @click="decide(l, false)" class="text-rose-600 font-bold">رفض</button></template></td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :paginator="loans" />
            </div>
            <div v-if="$can('hr.loans.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <h3 class="font-black text-slate-700">سلفة جديدة</h3>
                <select v-model="form.employee_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الموظف...</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
                <label class="block">التاريخ<input v-model="form.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                <label class="block">المبلغ<input v-model="form.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label>
                <label class="block">القسط الشهري<input v-model="form.monthly_installment" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label>
                <label class="block">أول شهر استقطاع<input v-model="form.first_deduction_month" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                <input v-model="form.reason" placeholder="السبب" class="w-full rounded-xl border-slate-200 text-xs" />
                <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="form.post(route('hr.loans.store'), { preserveScroll: true, onSuccess: () => form.reset('amount', 'monthly_installment', 'reason') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">تسجيل الطلب</button>
            </div>
        </div>
    </AppLayout>
</template>

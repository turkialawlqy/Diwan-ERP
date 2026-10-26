<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date } from '@/utils/format';

defineProps({ settlements: Object, employees: Array, reasons: Object, bankAccounts: Array });
const today = new Date().toISOString().substring(0, 10);
const form = useForm({ employee_id: '', termination_date: today, reason: 'resignation', salary_days: 0, other_dues: 0, other_deductions: 0, notes: '' });
const provision = useForm({ date: today });
const paying = ref(null);
const payForm = useForm({ date: today, bank_account_id: '' });
const approve = (s) => { if (confirm('اعتماد المخالصة وإنهاء خدمة الموظف؟')) router.post(route('hr.settlements.approve', s.id), {}, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head title="نهاية الخدمة" />
        <PageHeader title="مكافأة نهاية الخدمة والمخالصات" subtitle="المادة 84: نصف شهر عن كل سنة من الخمس الأولى ثم شهر كامل؛ المادة 85: الاستقالة ثلث/ثلثان/كامل" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'نهاية الخدمة' }]">
            <template #actions>
                <div v-if="$can('hr.settlements.approve')" class="flex gap-2 items-center"><input v-model="provision.date" type="date" class="rounded-xl border-slate-200 text-xs" /><button @click="provision.post(route('hr.settlements.provision'))" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تحديث مخصص نهاية الخدمة</button></div>
            </template>
        </PageHeader>
        <div class="grid lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الموظف</th><th class="px-3 py-2">السبب</th><th class="px-3 py-2">الخدمة</th><th class="px-3 py-2 text-end">المكافأة</th><th class="px-3 py-2 text-end">الإجازات</th><th class="px-3 py-2 text-end">الصافي</th><th class="px-3 py-2">الحالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in settlements.data" :key="s.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ s.employee?.name }}<div class="text-slate-400 font-mono">{{ date(s.termination_date) }}</div></td>
                            <td class="px-3 py-2 text-center">{{ reasons[s.reason] }}</td><td class="px-3 py-2 text-center font-mono">{{ Number(s.service_years).toFixed(2) }}</td>
                            <td class="px-3 py-2 text-end font-mono" :title="`الكاملة: ${money(s.eos_full)}`">{{ money(s.eos_amount) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(s.leave_encashment) }} <span class="text-slate-400">({{ s.leave_days }} يوم)</span></td>
                            <td class="px-3 py-2 text-end font-mono font-bold">{{ money(s.net_amount) }}</td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="s.status" /></td>
                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                <button v-if="s.status === 'draft' && $can('hr.settlements.approve')" @click="approve(s)" class="text-emerald-600 font-bold">اعتماد</button>
                                <button v-if="s.status === 'approved' && $can('hr.settlements.approve') && Number(s.net_amount) > 0" @click="paying = s" class="text-indigo-600 font-bold">صرف</button>
                            </td>
                        </tr>
                        <tr v-if="!settlements.data.length"><td colspan="8" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                </table>
                <Pagination :paginator="settlements" />
            </div>
            <div v-if="$can('hr.settlements.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <h3 class="font-black text-slate-700">احتساب مخالصة</h3>
                <select v-model="form.employee_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الموظف...</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
                <select v-model="form.reason" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in reasons" :key="k" :value="k">{{ l }}</option></select>
                <label class="block">تاريخ آخر يوم عمل<input v-model="form.termination_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                <label class="block">أيام راتب مستحقة لم تُصرف<input v-model="form.salary_days" type="number" step="1" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label>
                <div class="grid grid-cols-2 gap-2"><label>مستحقات أخرى<input v-model="form.other_dues" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label><label>استقطاعات أخرى<input v-model="form.other_deductions" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></label></div>
                <p class="text-slate-500">يُحتسب رصيد الإجازات وأرصدة السلف آلياً.</p>
                <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <button @click="form.post(route('hr.settlements.store'), { preserveScroll: true })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">احتساب</button>
            </div>
        </div>
        <Dialog :show="!!paying" title="صرف المخالصة" @close="paying = null">
            <div class="grid grid-cols-2 gap-3">
                <Field label="التاريخ"><input v-model="payForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الحساب"><select v-model="payForm.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <template #footer><button @click="payForm.post(route('hr.settlements.pay', paying.id), { onSuccess: () => (paying = null) })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">صرف {{ money(paying?.net_amount) }}</button></template>
        </Dialog>
    </AppLayout>
</template>

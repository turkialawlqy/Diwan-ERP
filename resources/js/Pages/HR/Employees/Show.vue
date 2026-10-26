<script setup>
import { confirmAction } from '@/composables/useDialog';
import HijriDate from '@/Components/UI/HijriDate.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ employee: Object, leaveBalance: Object, attendance: Array, month: String, payslips: Array, gratuity: Object });
const tab = ref('profile');
const docForm = useForm({ type: 'iqama', number: '', issue_date: '', expiry_date: '', notes: '', file: null });
const payForm = useForm({ kind: 'allowance', name: '', amount: 0, gosi_applicable: false, start_date: '', end_date: '' });
const docLabel = { iqama: 'إقامة', passport: 'جواز', contract: 'عقد عمل', medical_insurance: 'تأمين طبي', driving_license: 'رخصة قيادة', certificate: 'شهادة', other: 'أخرى' };
const changeMonth = (e) => router.get(route('hr.employees.show', props.employee.id), { month: e.target.value + '-01' }, { preserveState: true, preserveScroll: true });
const minutes = (m) => (m ? `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}` : '');
const remove = async (url, message) => { if (await confirmAction(message, { danger: true, confirmText: 'حذف' })) router.delete(url, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="employee.name" />
        <PageHeader :title="employee.name" :subtitle="`${employee.employee_number || ''} · ${employee.job_title} · ${employee.department_ref?.name || employee.department || ''}`" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الموظفون', href: route('hr.employees.index') }, { label: employee.name }]">
            <template #actions>
                <Link v-if="$can('hr.employees.edit')" :href="route('hr.employees.edit', employee.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تعديل</Link>
            </template>
        </PageHeader>
        <div class="flex flex-wrap gap-1 mb-3 border-b border-slate-200">
            <button v-for="[k, l] in [['profile', 'الملف'], ['documents', 'الوثائق'], ['pay', 'الراتب'], ['leaves', 'الإجازات'], ['attendance', 'الحضور'], ['payslips', 'قسائم الراتب']]" :key="k" @click="tab = k" :class="tab === k ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500'" class="px-3 py-2 text-xs font-bold border-b-2">{{ l }}</button>
        </div>

        <div v-if="tab === 'profile'" class="grid md:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>الجنسية</span><b>{{ employee.nationality }}</b></div>
                <div class="flex justify-between"><span>{{ employee.id_type === 'iqama' ? 'الإقامة' : 'الهوية' }}</span><b class="font-mono">{{ employee.national_id }}</b></div>
                <div class="flex justify-between"><span>انتهاء الهوية</span><b><HijriDate :value="employee.id_expiry" inline /></b></div>
                <div class="flex justify-between"><span>الجواز</span><b class="font-mono">{{ employee.passport_number || '—' }} </b></div><div v-if="employee.passport_expiry" class="flex justify-between"><span>انتهاء الجواز</span><b><HijriDate :value="employee.passport_expiry" inline /></b></div>
                <div class="flex justify-between"><span>الجوال</span><b>{{ employee.phone }}</b></div>
                <div class="flex justify-between"><span>المستخدم</span><b>{{ employee.user?.email || '—' }}</b></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>المباشرة</span><b class="font-mono">{{ date(employee.hire_date) }}</b></div>
                <div class="flex justify-between"><span>العقد</span><b>{{ employee.contract_type === 'limited' ? 'محدد حتى ' + date(employee.contract_end) : 'غير محدد المدة' }}</b></div>
                <div class="flex justify-between"><span>المدير</span><b>{{ employee.manager?.name || '—' }}</b></div>
                <div class="flex justify-between"><span>الوردية</span><b>{{ employee.shift?.name || 'الافتراضية' }}</b></div>
                <div class="flex justify-between"><span>الحالة</span><b>{{ employee.status }}</b></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>رصيد الإجازة السنوية</span><b class="font-mono">{{ leaveBalance.balance }} يوم</b></div>
                <div class="flex justify-between text-slate-500"><span>المستحق / المستخدم</span><span class="font-mono">{{ leaveBalance.accrued }} / {{ leaveBalance.used }}</span></div>
                <template v-if="gratuity">
                    <div class="flex justify-between"><span>مدة الخدمة</span><b class="font-mono">{{ gratuity.years.toFixed(2) }} سنة</b></div>
                    <div class="flex justify-between"><span>مكافأة نهاية الخدمة (حتى اليوم)</span><b class="font-mono">{{ money(gratuity.full) }}</b></div>
                </template>
            </div>
        </div>

        <div v-if="tab === 'documents'" class="grid md:grid-cols-3 gap-4">
            <div class="md:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">النوع</th><th class="px-3 py-2">الرقم</th><th class="px-3 py-2">الانتهاء</th><th class="px-3 py-2">الملف</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="d in employee.documents" :key="d.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ docLabel[d.type] }}</td><td class="px-3 py-2 text-center font-mono">{{ d.number }}</td><td class="px-3 py-2 text-center"><HijriDate :value="d.expiry_date" /></td>
                            <td class="px-3 py-2 text-center"><a v-if="d.url" :href="d.url" target="_blank" class="text-indigo-600 font-bold">عرض</a></td>
                            <td class="px-3 py-2 text-end"><button v-if="$can('hr.employees.edit')" @click="remove(route('hr.employees.documents.destroy', [employee.id, d.id]), 'حذف هذه الوثيقة؟')" class="text-rose-600">حذف</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="$can('hr.employees.edit')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <Field label="النوع"><select v-model="docForm.type" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in docLabel" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="الرقم"><input v-model="docForm.number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="grid grid-cols-2 gap-2"><Field label="الإصدار"><input v-model="docForm.issue_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field><Field label="الانتهاء"><input v-model="docForm.expiry_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field></div>
                <Field label="الملف (PDF/صورة)" :error="docForm.errors.file"><input type="file" @input="docForm.file = $event.target.files[0]" class="text-xs" /></Field>
                <button @click="docForm.post(route('hr.employees.documents.store', employee.id), { preserveScroll: true, forceFormData: true, onSuccess: () => docForm.reset() })" class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إضافة</button>
            </div>
        </div>

        <div v-if="tab === 'pay'" class="grid md:grid-cols-3 gap-4 text-xs">
            <div v-if="$can('hr.salaries.view')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <div class="flex justify-between"><span>الأساسي</span><b class="font-mono">{{ money(employee.basic_salary) }}</b></div>
                <div class="flex justify-between"><span>السكن</span><b class="font-mono">{{ money(employee.housing_allowance) }}</b></div>
                <div class="flex justify-between"><span>النقل</span><b class="font-mono">{{ money(employee.transport_allowance) }}</b></div>
                <div class="flex justify-between"><span>أخرى</span><b class="font-mono">{{ money(employee.other_allowances) }}</b></div>
                <div class="flex justify-between border-t pt-1 font-black"><span>الإجمالي</span><span class="font-mono">{{ money(employee.total_salary) }}</span></div>
                <div class="flex justify-between"><span>الآيبان</span><b class="font-mono">{{ employee.iban }}</b></div>
            </div>
            <div v-if="$can('hr.salaries.view')" class="md:col-span-2 bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">بدلات واستقطاعات ثابتة إضافية</h3>
                <div v-for="p in employee.pay_items" :key="p.id" class="flex justify-between py-1 border-b border-slate-50">
                    <span>{{ p.kind === 'allowance' ? '+' : '−' }} {{ p.name }} <span class="text-slate-400">{{ p.gosi_applicable ? '(خاضع للتأمينات)' : '' }} {{ date(p.start_date) }} → {{ date(p.end_date) }}</span></span>
                    <span><b class="font-mono">{{ money(p.amount) }}</b> <button v-if="$can('hr.employees.edit')" @click="remove(route('hr.employees.pay-items.destroy', [employee.id, p.id]), 'حذف هذا البند من راتب الموظف؟ يُطبّق على المسيرات القادمة.')" class="text-rose-600 ms-2">✕</button></span>
                </div>
                <div v-if="$can('hr.employees.edit')" class="grid grid-cols-6 gap-2 mt-3 items-end">
                    <select v-model="payForm.kind" class="rounded-xl border-slate-200 text-xs"><option value="allowance">بدل</option><option value="deduction">استقطاع</option></select>
                    <input v-model="payForm.name" placeholder="الاسم" class="col-span-2 rounded-xl border-slate-200 text-xs" />
                    <input v-model="payForm.amount" type="number" step="0.01" class="rounded-xl border-slate-200 text-xs font-mono" />
                    <label class="flex items-center gap-1"><input v-model="payForm.gosi_applicable" type="checkbox" class="rounded" /> تأمينات</label>
                    <button @click="payForm.post(route('hr.employees.pay-items.store', employee.id), { preserveScroll: true, onSuccess: () => payForm.reset() })" class="px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إضافة</button>
                </div>
            </div>
            <div v-if="$can('hr.salaries.view') && employee.salary_changes?.length" class="md:col-span-3 bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">سجل تعديلات الراتب</h3>
                <div class="overflow-x-auto"><table class="w-full min-w-[640px]">
                    <thead class="text-slate-500"><tr><th class="text-start py-1">السريان</th><th class="text-end">الأساسي</th><th class="text-end">السكن</th><th class="text-end">النقل</th><th class="text-end">أخرى</th><th class="text-start ps-3">السبب</th><th class="text-start">بواسطة</th></tr></thead>
                    <tbody>
                        <tr v-for="c in employee.salary_changes" :key="c.id" class="border-t border-slate-100">
                            <td class="py-1 font-mono">{{ date(c.effective_date) }}</td>
                            <td v-for="f in ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances']" :key="f" class="text-end font-mono">
                                {{ money(c[f]) }}<span v-if="Number(c.previous?.[f] || 0) !== Number(c[f])" class="block text-3xs text-slate-400 line-through">{{ money(c.previous?.[f]) }}</span>
                            </td>
                            <td class="ps-3">{{ c.reason || '—' }}</td><td>{{ c.creator?.name }}</td>
                        </tr>
                    </tbody>
                </table></div>
            </div>
            <div class="md:col-span-3 bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">السلف</h3>
                <div v-for="l in employee.loans" :key="l.id" class="flex justify-between py-1 border-b border-slate-50"><span>{{ date(l.date) }} — قسط {{ money(l.monthly_installment) }}</span><span class="font-mono">{{ money(l.repaid) }} / {{ money(l.amount) }} <StatusBadge :status="l.status" /></span></div>
                <p v-if="!employee.loans?.length" class="text-slate-400">لا توجد سلف</p>
            </div>
        </div>

        <div v-if="tab === 'leaves'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">النوع</th><th class="px-3 py-2">من</th><th class="px-3 py-2">إلى</th><th class="px-3 py-2">الأيام</th><th class="px-3 py-2">الحالة</th></tr></thead>
                <tbody><tr v-for="l in employee.leave_requests" :key="l.id" class="border-t border-slate-100"><td class="px-3 py-2">{{ l.type?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ date(l.start_date) }}</td><td class="px-3 py-2 text-center font-mono">{{ date(l.end_date) }}</td><td class="px-3 py-2 text-center">{{ l.days }}</td><td class="px-3 py-2 text-center"><StatusBadge :status="l.status" /></td></tr></tbody>
            </table>
        </div>

        <div v-if="tab === 'attendance'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="p-3 border-b border-slate-100"><input type="month" :value="month" @change="changeMonth" class="rounded-xl border-slate-200 text-xs" /></div>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">التاريخ</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2">دخول</th><th class="px-3 py-2">خروج</th><th class="px-3 py-2">العمل</th><th class="px-3 py-2">تأخير</th><th class="px-3 py-2">إضافي</th></tr></thead>
                <tbody><tr v-for="a in attendance" :key="a.id" class="border-t border-slate-100 text-center"><td class="px-3 py-1.5 font-mono">{{ date(a.date) }}</td><td>{{ a.status }}</td><td class="font-mono">{{ a.check_in?.substring(0, 5) }}</td><td class="font-mono">{{ a.check_out?.substring(0, 5) }}</td><td class="font-mono">{{ minutes(a.worked_minutes) }}</td><td class="font-mono text-rose-600">{{ a.late_minutes || '' }}</td><td class="font-mono text-emerald-600">{{ minutes(a.overtime_minutes) }}</td></tr></tbody>
            </table>
        </div>

        <div v-if="tab === 'payslips'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">الشهر</th><th class="px-3 py-2 text-end">الإجمالي</th><th class="px-3 py-2 text-end">الاستقطاعات</th><th class="px-3 py-2 text-end">الصافي</th><th></th></tr></thead>
                <tbody><tr v-for="p in payslips" :key="p.id" class="border-t border-slate-100"><td class="px-3 py-2 text-center font-mono">{{ p.payroll.month }}/{{ p.payroll.year }}</td><td class="px-3 py-2 text-end font-mono">{{ money(p.gross_salary) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(p.deductions) }}</td><td class="px-3 py-2 text-end font-mono font-bold">{{ money(p.net_salary) }}</td><td class="px-3 py-2 text-end"><Link :href="route('hr.payroll.payslip', [p.payroll_id, p.id])" class="text-indigo-600 font-bold">القسيمة</Link></td></tr></tbody>
            </table>
        </div>
    </AppLayout>
</template>

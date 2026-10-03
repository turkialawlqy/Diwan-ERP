<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ payroll: Object, item: Object, company: Object });
const printPage = () => window.print();
const earnings = [['الراتب الأساسي', 'basic_salary'], ['بدل السكن', 'housing_allowance'], ['بدل النقل', 'transport_allowance'], ['بدلات أخرى', 'other_allowances'], ['العمل الإضافي', 'overtime'], ['مكافآت وعمولات', 'bonuses']];
const deductions = [['غياب وإجازات غير مدفوعة', 'absence_deduction'], ['التأمينات الاجتماعية', 'gosi_employee'], ['أقساط السلف', 'loan_deduction'], ['جزاءات واستقطاعات', 'other_deductions']];
</script>

<template>
    <AppLayout>
        <Head title="قسيمة راتب" />
        <div class="max-w-2xl mx-auto bg-white border border-slate-200 rounded-2xl p-6 text-xs">
            <div class="flex justify-between items-start border-b pb-3 mb-3">
                <div><h1 class="text-base font-black">{{ company?.name }}</h1><p class="text-slate-500">س.ت {{ company?.commercial_registration }}</p></div>
                <div class="text-end"><h2 class="font-black">قسيمة راتب</h2><p class="font-mono">{{ payroll.month }}/{{ payroll.year }}</p><button @click="printPage" class="mt-1 text-indigo-600 font-bold print:hidden">طباعة</button></div>
            </div>
            <div class="grid grid-cols-2 gap-2 mb-4">
                <div>الموظف: <b>{{ item.employee?.name }}</b></div><div>الرقم الوظيفي: <b class="font-mono">{{ item.employee?.employee_number }}</b></div>
                <div>المسمى: <b>{{ item.employee?.job_title }}</b></div><div>تاريخ المباشرة: <b class="font-mono">{{ date(item.employee?.hire_date) }}</b></div>
                <div>أيام العمل: <b>{{ item.days_worked }}</b></div><div>الآيبان: <b class="font-mono">{{ item.employee?.iban }}</b></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><h3 class="font-black border-b mb-1">المستحقات</h3><div v-for="[l, k] in earnings" :key="k" class="flex justify-between py-0.5"><span>{{ l }}</span><span class="font-mono">{{ money(item[k]) }}</span></div></div>
                <div><h3 class="font-black border-b mb-1">الاستقطاعات</h3><div v-for="[l, k] in deductions" :key="k" class="flex justify-between py-0.5"><span>{{ l }}</span><span class="font-mono">{{ money(item[k]) }}</span></div></div>
            </div>
            <div class="flex justify-between border-t mt-3 pt-2 text-sm font-black"><span>صافي الراتب</span><span class="font-mono">{{ money(item.net_salary) }}</span></div>
            <p v-if="item.breakdown?.overtime_hours" class="text-slate-500 mt-2">ساعات إضافي: {{ item.breakdown.overtime_hours }} · أيام غياب: {{ item.breakdown.absent_days }} · وعاء التأمينات: {{ money(item.breakdown.gosi_base) }}</p>
        </div>
    </AppLayout>
</template>

<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { money, date } from '@/utils/format';
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

defineProps({ employees: Object, stats: Object, expiring: Array, departments: Array, filters: Object });
const columns = [
    { key: 'employee_number', label: 'الرقم', type: 'mono' },
    { key: 'name', label: 'الاسم' },
    { key: 'job_title', label: 'المسمى' },
    { key: 'department_ref.name', label: 'الإدارة' },
    { key: 'nationality', label: 'الجنسية' },
    { key: 'hire_date', label: 'المباشرة', type: 'date' },
    { key: 'status', label: 'الحالة' },
];
const statuses = [{ value: 'active', label: 'على رأس العمل' }, { value: 'on_leave', label: 'في إجازة' }, { value: 'suspended', label: 'موقوف' }, { value: 'terminated', label: 'منتهية خدمته' }];
const docLabel = { national_id: 'الهوية', iqama: 'الإقامة', border_number: 'رقم الحدود', passport: 'الجواز', contract: 'العقد', medical_insurance: 'التأمين الطبي', driving_license: 'الرخصة', certificate: 'شهادة', other: 'وثيقة' };
</script>

<template>
    <AppLayout>
        <Head title="الموظفون" />
        <PageHeader title="الموظفون" subtitle="ملفات الموظفين، الوثائق، الرواتب والبدلات" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الموظفون' }]">
            <template #actions><ImportButton v-if="$can('hr.employees.create')" type="employees" title="استيراد الموظفين من Excel" hint="الموظف الموجود (بنفس الرقم الوظيفي) تُحدّث بياناته الشخصية فقط. الرواتب والآيبان تُستورد لمن يملك صلاحية الرواتب." /><Link v-if="$can('hr.employees.create')" :href="route('hr.employees.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> موظف جديد</Link></template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="على رأس العمل" :value="stats.active" />
            <StatCard label="سعوديون" :value="stats.saudi" tone="emerald" />
            <StatCard label="غير سعوديين" :value="stats.non_saudi" />
            <StatCard v-if="stats.monthly_payroll !== null" label="الرواتب الشهرية" :value="money(stats.monthly_payroll)" tone="indigo" />
        </div>
        <div v-if="expiring.length" class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs">
            <div class="font-bold text-amber-800 flex items-center gap-1 mb-1"><ExclamationTriangleIcon class="w-4 h-4" /> وثائق تنتهي خلال 60 يوماً</div>
            <div class="grid md:grid-cols-2 gap-x-6">
                <Link v-for="(d, i) in expiring" :key="i" :href="route('hr.employees.show', d.employee_id)" class="text-amber-700 hover:underline">{{ d.name }} — {{ docLabel[d.type] || d.type }} — <span class="font-mono">{{ date(d.expiry) }}</span></Link>
            </div>
        </div>
        <DocumentTable :paginator="employees" :columns="columns" :filters="filters" :statuses="statuses" search-route="hr.employees.index" :link="(r) => route('hr.employees.show', r.id)" />
    </AppLayout>
</template>

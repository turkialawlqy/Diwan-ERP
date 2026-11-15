<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ImportButton from '@/Components/UI/ImportButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { money, date } from '@/utils/format';
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ employees: Object, stats: Object, expiring: Array, departments: Array, filters: Object });
const columns = computed(() => ([
    { key: 'employee_number', label: t('hr2_hr_employees_index.number'), type: 'mono' },
    { key: 'name', label: t('hr2_hr_employees_index.name') },
    { key: 'job_title', label: t('hr2_hr_employees_index.title') },
    { key: 'department_ref.name', label: t('hr2_hr_employees_index.department') },
    { key: 'nationality', label: t('hr2_hr_employees_index.nationality') },
    { key: 'hire_date', label: t('hr2_hr_employees_index.start'), type: 'date' },
    { key: 'status', label: t('hr2_hr_employees_index.status'), type: 'status' },
]));
const statuses = computed(() => ([{ value: 'active', label: t('hr2_hr_employees_index.active') }, { value: 'on_leave', label: t('hr2_hr_employees_index.on_leave') }, { value: 'suspended', label: t('hr2_hr_employees_index.suspended') }, { value: 'terminated', label: t('hr2_hr_employees_index.service_ended') }]));
const docLabel = computed(() => ({ national_id: t('hr2_hr_employees_index.id'), iqama: t('hr2_hr_employees_index.residence_permit'), border_number: t('hr2_hr_employees_index.border_number'), passport: t('hr2_hr_employees_index.passport'), contract: t('hr2_hr_employees_index.contract'), medical_insurance: t('hr2_hr_employees_index.medical_insurance'), driving_license: t('hr2_hr_employees_index.license'), certificate: t('hr2_hr_employees_index.certificate'), other: t('hr2_hr_employees_index.document') }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_employees_index.employees')" />
        <PageHeader :title="$t('hr_employees_index.employees')" :subtitle="$t('hr_employees_index.employee_files_documents_salarie')" :crumbs="[{ label: $t('hr2_hr_employees_index.human_resources') }, { label: $t('hr2_hr_employees_index.employees') }]">
            <template #actions><Link v-if="$can('hr.employees.create')" :href="route('hr.employees.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('hr_employees_index.new_employee') }}</Link><ImportButton v-if="$can('hr.employees.create')" type="employees" :title="$t('hr_employees_index.import_employees_from_excel')" :hint="$t('hr_employees_index.an_existing_employee_same_employ')" />
</template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('hr_employees_index.active')" :value="stats.active" />
            <StatCard :label="$t('hr_employees_index.saudis')" :value="stats.saudi" tone="emerald" />
            <StatCard :label="$t('hr_employees_index.non_saudis')" :value="stats.non_saudi" />
            <StatCard v-if="stats.monthly_payroll !== null" :label="$t('hr_employees_index.monthly_salaries')" :value="money(stats.monthly_payroll)" tone="indigo" />
        </div>
        <div v-if="expiring.length" class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs">
            <div class="font-bold text-amber-800 flex items-center gap-1 mb-1"><ExclamationTriangleIcon class="w-4 h-4" /> {{ $t('hr_employees_index.documents_expiring_within_60_day') }}</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">
                <Link v-for="(d, i) in expiring" :key="i" :href="route('hr.employees.show', d.employee_id)" class="text-amber-700 hover:underline">{{ d.name }} — {{ docLabel[d.type] || d.type }} — <span class="font-mono">{{ date(d.expiry) }}</span></Link>
            </div>
        </div>
        <DocumentTable :paginator="employees" :columns="columns" :filters="filters" :statuses="statuses" search-route="hr.employees.index" :link="(r) => route('hr.employees.show', r.id)" :edit-link="$can('hr.employees.edit') ? (r) => route('hr.employees.edit', r.id) : null" :delete-link="$can('hr.employees.delete') ? (r) => route('hr.employees.destroy', r.id) : null" :delete-confirm="(r) => $t('hr2_hr_employees_index.delete_the_employee_n', { n0: r.name })" />
    </AppLayout>
</template>

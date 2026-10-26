<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { money, date } from '@/utils/format';
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

defineProps({ projects: Object, expiringGuarantees: Array, filters: Object });
const columns = [
    { key: 'code', label: 'الرمز', type: 'mono' },
    { key: 'name', label: 'المشروع' },
    { key: 'customer.name', label: 'العميل' },
    { key: 'manager.name', label: 'المدير' },
    { key: 'end_date', label: 'تاريخ الانتهاء', type: 'date' },
    { key: 'contract_value', label: 'قيمة العقد', type: 'money' },
    { key: 'status', label: 'الحالة' },
];
const statuses = [{ value: 'planning', label: 'تخطيط' }, { value: 'active', label: 'قيد التنفيذ' }, { value: 'on_hold', label: 'متوقف' }, { value: 'completed', label: 'منجز' }, { value: 'closed', label: 'مغلق' }];
</script>

<template>
    <AppLayout>
        <Head title="المشاريع" />
        <PageHeader title="المشاريع والعقود" subtitle="جداول الكميات، المستخلصات، مقاولو الباطن، التكاليف والربحية" :crumbs="[{ label: 'المقاولات' }, { label: 'المشاريع' }]">
            <template #actions>
                <Link v-if="$can('projects.guarantees.view')" :href="route('projects.guarantees.index')" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">خطابات الضمان</Link>
                <Link v-if="$can('projects.projects.create')" :href="route('projects.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> مشروع جديد</Link>
            </template>
        </PageHeader>
        <div v-if="expiringGuarantees.length" class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs">
            <div class="font-bold text-amber-800 flex items-center gap-1 mb-1"><ExclamationTriangleIcon class="w-4 h-4" /> خطابات ضمان تنتهي خلال 30 يوماً</div>
            <div v-for="g in expiringGuarantees" :key="g.id" class="text-amber-700">{{ g.number }} — {{ g.bank_name }} — {{ money(g.amount) }} — تنتهي {{ date(g.expiry_date) }} <span v-if="g.project">({{ g.project.code }})</span></div>
        </div>
        <DocumentTable :paginator="projects" :columns="columns" :filters="filters" :statuses="statuses" search-route="projects.index" :link="(r) => route('projects.show', r.id)" />
    </AppLayout>
</template>

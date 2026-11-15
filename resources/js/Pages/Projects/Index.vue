<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { money, date } from '@/utils/format';
import { PlusIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ projects: Object, expiringGuarantees: Array, filters: Object });
const columns = computed(() => ([
    { key: 'code', label: t('prj_projects_index.code'), type: 'mono' },
    { key: 'name', label: t('prj_projects_index.project') },
    { key: 'customer.name', label: t('prj_projects_index.customer') },
    { key: 'manager.name', label: t('prj_projects_index.manager') },
    { key: 'end_date', label: t('prj_projects_index.expiry_date'), type: 'date' },
    { key: 'contract_value', label: t('prj_projects_index.contract_value'), type: 'money' },
    { key: 'status', label: t('prj_projects_index.status') },
]));
const statuses = computed(() => ([{ value: 'planning', label: t('prj_projects_index.planning') }, { value: 'active', label: t('prj_projects_index.in_progress') }, { value: 'on_hold', label: t('prj_projects_index.on_hold') }, { value: 'completed', label: t('prj_projects_index.done') }, { value: 'closed', label: t('prj_projects_index.closed') }]));
</script>

<template>
    <AppLayout>
        <Head :title="$t('prj_projects_index.projects')" />
        <PageHeader :title="$t('prj_projects_index.projects_and_contracts')" :subtitle="$t('prj_projects_index.bills_of_quantities_certificates')" :crumbs="[{ label: $t('prj_projects_index.contracting') }, { label: $t('prj_projects_index.projects') }]">
            <template #actions>
                <Link v-if="$can('projects.guarantees.view')" :href="route('projects.guarantees.index')" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('prj_projects_index.bank_guarantees') }}</Link>
                <Link v-if="$can('projects.projects.create')" :href="route('projects.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('prj_projects_index.new_project') }}</Link>
            </template>
        </PageHeader>
        <div v-if="expiringGuarantees.length" class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs">
            <div class="font-bold text-amber-800 flex items-center gap-1 mb-1"><ExclamationTriangleIcon class="w-4 h-4" /> {{ $t('prj_projects_index.guarantees_expiring_within_30_da') }}</div>
            <div v-for="g in expiringGuarantees" :key="g.id" class="text-amber-700">{{ $t('prj_projects_index.n_n_n_expires_n', { n0: g.number, n1: g.bank_name, n2: money(g.amount), n3: date(g.expiry_date) }) }} <span v-if="g.project">({{ g.project.code }})</span></div>
        </div>
        <DocumentTable :paginator="projects" :columns="columns" :filters="filters" :statuses="statuses" search-route="projects.index" :link="(r) => route('projects.show', r.id)" />
    </AppLayout>
</template>

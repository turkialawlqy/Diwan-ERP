<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ requests: Object, filters: Object });
const { t } = useI18n();
const columns = computed(() => [
    { key: 'request_number', label: t('prq.number'), type: 'mono' },
    { key: 'request_date', label: t('prq.date'), type: 'date' },
    { key: 'purpose', label: t('prq.purpose') },
    { key: 'payee_label', label: t('prq.payee') },
    { key: 'amount', label: t('prq.amount'), type: 'money' },
    { key: 'requester.name', label: t('prq.requester') },
    { key: 'status', label: t('prq.status'), type: 'status' },
]);
const statuses = computed(() => ['draft', 'pending_approval', 'approved', 'rejected', 'paid', 'cancelled'].map((value) => ({ value, label: t('status.' + value) })));
</script>

<template>
    <AppLayout>
        <Head :title="$t('prq.title')" />
        <PageHeader year-scoped :title="$t('prq.title')" :subtitle="$t('prq.subtitle')" :crumbs="[{ label: $t('nav.finance') }, { label: $t('prq.title') }]">
            <template #actions>
                <Link v-if="$can('finance.payment_requests.create')" :href="route('finance.payment-requests.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('prq.new') }}</Link>
            </template>
        </PageHeader>
        <DocumentTable :paginator="requests" :columns="columns" :filters="filters" :statuses="statuses" search-route="finance.payment-requests.index"
            :link="(r) => route('finance.payment-requests.show', r.id)"
            :edit-link="(r) => (['draft', 'rejected'].includes(r.status) && $can('finance.payment_requests.edit') ? route('finance.payment-requests.edit', r.id) : null)"
            :delete-link="(r) => (r.status === 'draft' && $can('finance.payment_requests.delete') ? route('finance.payment-requests.destroy', r.id) : null)" />
    </AppLayout>
</template>

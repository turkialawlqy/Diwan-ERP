<script setup>
import RowActions from '@/Components/UI/RowActions.vue';
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ tickets: Object, filters: Object, metrics: Object, agents: Array, statuses: Object, priorities: Object });
const f = reactive({ ...props.filters });
const apply = () => router.get(route('support.tickets.index'), {
    search: f.search || undefined, status: f.status, priority: f.priority || undefined, assignee: f.assignee || undefined, overdue: f.overdue ? 1 : undefined,
}, { preserveState: true, replace: true });
const prio = { low: 'bg-slate-100 text-slate-600', normal: 'bg-sky-50 text-sky-700', high: 'bg-amber-50 text-amber-700', urgent: 'bg-rose-50 text-rose-700' };
const stat = { open: 'bg-emerald-50 text-emerald-700', pending: 'bg-sky-50 text-sky-700', resolved: 'bg-violet-50 text-violet-700', closed: 'bg-slate-100 text-slate-500' };
const due = (t) => (t.status === 'open' || t.status === 'pending' ? t.resolution_due_at?.slice(0, 16).replace('T', ' ') : '');
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_support_tickets_index.support_tickets')" />
        <PageHeader :title="$t('x_support_tickets_index.support_tickets')" :subtitle="$t('x_support_tickets_index.customer_requests_complaints_and')" :crumbs="[{ label: $t('x_support_tickets_index.customer_service') }, { label: $t('x_support_tickets_index.tickets') }]">
            <template #actions>
                <Link v-if="$can('support.tickets.create')" :href="route('support.tickets.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-ticket><PlusIcon class="w-4 h-4" /> {{ $t('x_support_tickets_index.new_ticket') }}</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" data-metrics>
            <StatCard :label="$t('x_support_tickets_index.open_tickets')" :value="metrics.open" tone="indigo" />
            <StatCard :label="$t('x_support_tickets_index.unassigned')" :value="metrics.unassigned" :tone="metrics.unassigned ? 'amber' : 'slate'" />
            <StatCard :label="$t('x_support_tickets_index.past_the_deadline')" :value="metrics.overdue" :tone="metrics.overdue ? 'rose' : 'slate'" />
            <StatCard :label="$t('x_support_tickets_index.average_resolution_hours')" :value="metrics.avg_resolution_hours ?? '—'" />
            <StatCard :label="$t('x_support_tickets_index.deadline_compliance_rating')" :value="`${metrics.sla_compliance != null ? metrics.sla_compliance + '%' : '—'} · ${metrics.avg_rating ?? '—'}★`" tone="emerald" />
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex flex-wrap gap-2 text-xs">
            <input v-model="f.search" @input="apply" :placeholder="$t('x_support_tickets_index.search_by_ticket_number_subject')" class="flex-1 min-w-[12rem] rounded-xl border-slate-200 text-xs" />
            <select v-model="f.status" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="active">{{ $t('x_support_tickets_index.open_and_awaiting_the_customer') }}</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option><option value="all">{{ $t('x_support_tickets_index.all') }}</option></select>
            <select v-model="f.priority" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('x_support_tickets_index.all_priorities') }}</option><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.assignee" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('x_support_tickets_index.all_owners') }}</option><option value="me">{{ $t('x_support_tickets_index.my_tickets') }}</option><option value="none">{{ $t('x_support_tickets_index.unassigned') }}</option><option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option></select>
            <label class="flex items-center gap-1 font-bold text-slate-600"><input type="checkbox" v-model="f.overdue" @change="apply" class="rounded" /> {{ $t('x_support_tickets_index.overdue_only') }}</label>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr>
                    <th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.ticket') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.subject') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.customer') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.priority') }}</th>
                    <th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.status') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.owner') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_tickets_index.resolution_deadline') }}</th><th class="px-4 py-2 w-12"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="t in tickets.data" :key="t.id" class="border-t border-slate-100" :class="t.overdue ? 'bg-rose-50/40' : ''" :data-ticket="t.number">
                        <td class="px-4 py-2 font-mono font-bold text-indigo-600"><Link :href="route('support.tickets.show', t.id)">{{ t.number }}</Link></td>
                        <td class="px-4 py-2 font-bold">{{ t.subject }}<span v-if="t.category" class="block font-normal text-slate-400">{{ t.category.name }}</span></td>
                        <td class="px-4 py-2">{{ t.customer?.name || t.contact_name || '—' }}</td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-bold" :class="prio[t.priority]">{{ priorities[t.priority] }}</span></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-bold" :class="stat[t.status]">{{ statuses[t.status] }}</span></td>
                        <td class="px-4 py-2">{{ t.assignee?.name || '—' }}</td>
                        <td class="px-4 py-2 font-mono" :class="t.overdue ? 'text-rose-600 font-bold' : 'text-slate-500'">{{ due(t) }}<span v-if="t.overdue" class="block text-3xs">{{ $t('x_support_tickets_index.overdue') }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('support.tickets.show', t.id)" /></td>
                    </tr>
                    <tr v-if="!tickets.data.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ $t('x_support_tickets_index.no_tickets') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="tickets" />
    </AppLayout>
</template>

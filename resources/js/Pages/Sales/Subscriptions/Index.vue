<script setup>
import RowActions from '@/Components/UI/RowActions.vue';
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

const props = defineProps({ subscriptions: Object, filters: Object, stats: Object, renewals: Array, plans: Array, statuses: Object });
const f = reactive({ search: props.filters.search, status: props.filters.status, plan: props.filters.plan });
const apply = () => router.get(route('sales.subscriptions.index'), { search: f.search || undefined, status: f.status || undefined, plan: f.plan || undefined }, { preserveState: true, replace: true });
const tone = { trial: 'bg-sky-50 text-sky-700 border-sky-200', active: 'bg-emerald-50 text-emerald-700 border-emerald-200', paused: 'bg-slate-100 text-slate-600 border-slate-200', past_due: 'bg-rose-50 text-rose-700 border-rose-200', cancelled: 'bg-slate-100 text-slate-500 border-slate-200', expired: 'bg-amber-50 text-amber-700 border-amber-200' };
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_sub_list.customer_subscriptions')" />
        <PageHeader :title="$t('s_sub_list.customer_subscriptions')" :subtitle="$t('s_sub_list.recurring_services_invoiced_auto')" :crumbs="[{ label: 'المبيعات' }, { label: 'اشتراكات العملاء' }]">
            <template #actions>
                <Link v-if="$can('sales.service_plans.view')" :href="route('sales.service-plans.index')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 text-xs font-bold">{{ $t('s_sub_list.plans') }}</Link>
                <Link v-if="$can('sales.subscriptions.create')" :href="route('sales.subscriptions.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-subscription><PlusIcon class="w-4 h-4" /> {{ $t('s_sub_list.new_subscription') }}</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('s_sub_list.monthly_recurring_revenue_before')" :value="money(stats.mrr)" tone="emerald" />
            <StatCard :label="$t('s_sub_list.active_subscriptions')" :value="stats.live" tone="indigo" />
            <StatCard :label="$t('s_sub_list.past_due')" :value="stats.past_due" :tone="stats.past_due ? 'rose' : 'slate'" />
            <StatCard :label="$t('s_sub_list.cancelled_in_the_last_30_days')" :value="stats.cancelled_30" tone="amber" />
        </div>

        <div v-if="renewals.length" class="bg-white border border-slate-200 rounded-2xl p-4 mb-4" data-renewals>
            <h2 class="text-xs font-bold text-slate-800 mb-2">{{ $t('s_sub_list.renewals_in_the_next_30_days') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 text-xs">
                <Link v-for="r in renewals" :key="r.id" :href="route('sales.subscriptions.show', r.id)" class="flex items-center justify-between py-1 border-b border-slate-50 hover:text-indigo-600">
                    <span class="font-bold">{{ r.customer }} <span class="font-mono text-slate-400">{{ r.number }}</span></span>
                    <span class="font-mono" :class="r.ends ? 'text-amber-600' : 'text-slate-600'">{{ r.date }} · {{ r.ends ? $t('s_sub_list.ends') : money(r.amount) }}</span>
                </Link>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex flex-wrap gap-2 text-xs">
            <input v-model="f.search" @input="apply" :placeholder="$t('s_sub_list.search_by_subscription_number_or')" class="flex-1 min-w-[12rem] rounded-xl border-slate-200 text-xs" />
            <select v-model="f.status" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('s_sub_list.all_statuses') }}</option><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option></select>
            <select v-model="f.plan" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('s_sub_list.all_plans') }}</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr>
                    <th class="px-4 py-2 text-start">{{ $t('s_sub_list.subscription') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub_list.customer') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub_list.plan') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub_list.value_period') }}</th>
                    <th class="px-4 py-2 text-start">{{ $t('s_sub_list.next_renewal') }}</th><th class="px-4 py-2 text-start">{{ $t('s_sub_list.status') }}</th><th class="px-4 py-2 w-16"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in subscriptions.data" :key="s.id" class="border-t border-slate-100" :data-subscription="s.number">
                        <td class="px-4 py-2 font-mono font-bold text-indigo-600">{{ s.number }}</td>
                        <td class="px-4 py-2 font-bold">{{ s.customer?.name }}</td>
                        <td class="px-4 py-2">{{ s.plan?.name }}<span v-if="s.pending_plan_id" class="block text-3xs text-amber-600">{{ $t('s_sub_list.plan_change_at_renewal') }}</span></td>
                        <td class="px-4 py-2 font-mono">{{ money(Number(s.quantity) * Number(s.unit_price) * (1 + Number(s.tax_rate) / 100)) }}</td>
                        <td class="px-4 py-2 font-mono">{{ s.recurring?.next_run_date?.slice(0, 10) || '—' }}<span v-if="s.cancel_at_period_end" class="block text-3xs text-amber-600">{{ $t('s_sub_list.cancels_at_the_end_of_the_period') }}</span></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full border text-[11px] font-bold" :class="tone[s.status]">{{ statuses[s.status] }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('sales.subscriptions.show', s.id)" /></td>
                    </tr>
                    <tr v-if="!subscriptions.data.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('s_sub_list.no_subscriptions') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="subscriptions" />
    </AppLayout>
</template>

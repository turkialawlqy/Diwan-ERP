<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';
import { useI18n } from 'vue-i18n';

defineProps({ runs: Object });
const { t } = useI18n();
const basisLabel = (b) => ({ revenue: t('alloc.b_revenue'), direct_cost: t('alloc.b_direct_cost'), hours: t('alloc.b_hours') })[b];
const statusLabel = (s) => (s === 'posted' ? t('alloc.posted') : t('alloc.reversed'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('alloc.title')" />
        <PageHeader :title="$t('alloc.title')" :subtitle="$t('alloc.subtitle')" :crumbs="[{ label: $t('nav.projects') }, { label: $t('alloc.title') }]">
            <template #actions>
                <Link v-if="$can('projects.allocations.create')" :href="route('projects.allocations.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('alloc.new') }}</Link>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="text-start px-3 py-2">{{ $t('alloc.number') }}</th><th class="text-start px-3 py-2">{{ $t('alloc.period') }}</th><th class="text-start px-3 py-2">{{ $t('alloc.basis') }}</th>
                        <th class="text-end px-3 py-2">{{ $t('alloc.pool') }}</th><th class="text-start px-3 py-2">{{ $t('alloc.status') }}</th><th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="run in runs.data" :key="run.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ run.number }}</td>
                        <td class="px-3 py-2 font-mono">{{ run.period_from?.slice(0, 10) }} → {{ run.period_to?.slice(0, 10) }}</td>
                        <td class="px-3 py-2">{{ basisLabel(run.basis) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(run.pool_amount) }}</td>
                        <td class="px-3 py-2"><span :class="run.status === 'posted' ? 'text-emerald-700' : 'text-slate-400'" class="font-bold">{{ statusLabel(run.status) }}</span></td>
                        <td class="px-3 py-2 text-end"><RowActions :view="$can('projects.allocations.view') ? route('projects.allocations.show', run.id) : null" :remove="run.status === 'posted' && $can('projects.allocations.delete') ? route('projects.allocations.destroy', run.id) : null" :confirm="$t('alloc.confirm_reverse')" /></td>
                    </tr>
                    <tr v-if="!runs.data.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('alloc.empty') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import { confirmAction } from '@/composables/useDialog';
import { money } from '@/utils/format';
import { useI18n } from 'vue-i18n';

const props = defineProps({ run: Object, accounts: Array });
const { t } = useI18n();
const basisLabel = (b) => ({ revenue: t('alloc.b_revenue'), direct_cost: t('alloc.b_direct_cost'), hours: t('alloc.b_hours') })[b];
const statusLabel = (s) => (s === 'posted' ? t('alloc.posted') : t('alloc.reversed'));
const reverse = async () => {
    if (await confirmAction(t('alloc.confirm_reverse'), { title: t('alloc.reverse') })) router.delete(route('projects.allocations.destroy', props.run.id));
};
</script>

<template>
    <AppLayout>
        <Head :title="run.number" />
        <PageHeader :title="`${$t('alloc.title')} ${run.number}`" :subtitle="`${run.period_from?.slice(0, 10)} → ${run.period_to?.slice(0, 10)} · ${basisLabel(run.basis)} · ${statusLabel(run.status)}`" :crumbs="[{ label: $t('nav.projects') }, { label: $t('alloc.title'), href: route('projects.allocations.index') }, { label: run.number }]">
            <template #actions>
                <button v-if="run.status === 'posted' && $can('projects.allocations.delete')" type="button" @click="reverse" class="px-3 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold" data-allocation-reverse>{{ $t('alloc.reverse') }}</button>
                <ExportButtons report="project-allocation" :params="{ run_id: run.id }" permission="projects.allocations.export" />
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-4">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('alloc.project') }}</th><th class="text-end px-3 py-2">{{ $t('alloc.basis_value') }}</th><th class="text-end px-3 py-2">{{ $t('alloc.share') }}</th><th class="text-end px-3 py-2">{{ $t('alloc.allocated') }}</th></tr></thead>
                <tbody>
                    <tr v-for="l in run.lines" :key="l.id" class="border-t border-slate-100"><td class="px-3 py-2">{{ l.project.code }} - {{ l.project.name }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.basis_value) }}</td><td class="px-3 py-2 text-end font-mono">{{ Number(l.share_pct).toFixed(2) }}</td><td class="px-3 py-2 text-end font-mono font-bold">{{ money(l.amount) }}</td></tr>
                </tbody>
                <tfoot class="bg-slate-100 font-black"><tr><td class="px-3 py-2" colspan="3">{{ $t('alloc.total') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(run.pool_amount) }}</td></tr></tfoot>
            </table>
        </div>
        <div class="text-xs text-slate-600 space-y-1">
            <div>{{ $t('alloc.credits') }}: {{ accounts.map((a) => `${a.code} - ${a.name_ar}`).join('، ') }}</div>
            <div v-if="run.journal_entry">{{ $t('alloc.journal') }}: <span class="font-mono">{{ run.journal_entry.entry_number }}</span></div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';

/** The stock-ins made by the warehouse keepers and where each one stands with accounting. */
defineProps({ drafts: Object });
const { t } = useI18n();
const statusLabel = computed(() => ({ pending: t('stockin.status_pending'), completed: t('stockin.status_completed'), cancelled: t('stockin.status_cancelled') }));
const tone = { pending: 'bg-amber-50 text-amber-700 border-amber-200', completed: 'bg-emerald-50 text-emerald-700 border-emerald-200', cancelled: 'bg-slate-100 text-slate-500 border-slate-200' };
</script>

<template>
    <AppLayout>
        <Head :title="$t('stockin.title')" />
        <PageHeader :title="$t('stockin.title')" :subtitle="$t('stockin.list_subtitle')" :crumbs="[{ label: 'المخزون' }, { label: $t('stockin.title') }]">
            <template #actions>
                <Link v-if="$can('inventory.stock_in.create')" :href="route('inventory.stock-in.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700" data-stockin-new>+ {{ $t('stockin.new') }}</Link>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.number') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('ui.date') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.warehouse') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.lines') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.by') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('ui.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in drafts.data" :key="d.id" class="border-t border-slate-100" data-stockin-row>
                        <td class="px-3 py-2 font-mono font-bold">{{ d.number }}</td>
                        <td class="px-3 py-2 font-mono">{{ d.date }}</td>
                        <td class="px-3 py-2">{{ d.warehouse }}</td>
                        <td class="px-3 py-2 font-mono">{{ d.lines }}</td>
                        <td class="px-3 py-2">{{ d.by }}</td>
                        <td class="px-3 py-2"><span :class="tone[d.status]" class="px-2 py-0.5 rounded-full border font-bold">{{ statusLabel[d.status] }}</span></td>
                    </tr>
                    <tr v-if="!drafts.data.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ $t('stockin.empty') }}</td></tr>
                </tbody>
            </table>
            <Pagination :links="drafts.links" />
        </div>
    </AppLayout>
</template>

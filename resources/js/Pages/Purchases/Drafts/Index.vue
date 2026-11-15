<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { money } from '@/utils/format';

/** Stock-ins waiting for accounting: complete each into a supplier invoice (supplier, date, prices) or discard it. */
const props = defineProps({ status: String, drafts: Object, pending: Number });
const { t } = useI18n();

const filters = computed(() => [['pending', t('pdraft.pending')], ['completed', t('pdraft.completed')], ['cancelled', t('pdraft.cancelled')], ['all', t('pdraft.all')]]);
const go = (status) => router.get(route('purchases.drafts.index'), { status }, { preserveState: true });
const tone = { pending: 'bg-amber-50 text-amber-700 border-amber-200', completed: 'bg-emerald-50 text-emerald-700 border-emerald-200', cancelled: 'bg-slate-100 text-slate-500 border-slate-200' };
const statusLabel = computed(() => ({ pending: t('pdraft.pending'), completed: t('pdraft.completed'), cancelled: t('pdraft.cancelled') }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('pdraft.title')" />
        <PageHeader :title="$t('pdraft.title')" :subtitle="$t('pdraft.subtitle')" :crumbs="[{ label: 'المشتريات' }, { label: $t('pdraft.title') }]" />

        <div class="flex flex-wrap gap-2 mb-3 text-xs" data-draft-filters>
            <button v-for="[key, label] in filters" :key="key" type="button" @click="go(key)" :class="status === key ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-600'" class="px-3 py-1.5 rounded-xl font-bold">
                {{ label }}<span v-if="key === 'pending' && pending" class="ms-1.5 px-1.5 rounded-full bg-rose-500 text-white">{{ pending }}</span>
            </button>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.number') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('ui.date') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.warehouse') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('stockin.by') }}</th>
                        <th class="px-3 py-2 text-end">{{ $t('pdraft.provisional_value') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('ui.status') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in drafts.data" :key="d.id" class="border-t border-slate-100" data-draft-row>
                        <td class="px-3 py-2 font-mono font-bold">{{ d.number }}</td>
                        <td class="px-3 py-2 font-mono">{{ d.date }}</td>
                        <td class="px-3 py-2">{{ d.warehouse }}</td>
                        <td class="px-3 py-2">{{ d.by }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(d.value) }}</td>
                        <td class="px-3 py-2">
                            <span :class="tone[d.status]" class="px-2 py-0.5 rounded-full border font-bold">{{ statusLabel[d.status] }}</span>
                            <Link v-if="d.invoice_id" :href="route('purchases.invoices.show', d.invoice_id)" class="ms-2 font-mono text-indigo-700 hover:underline">{{ d.invoice_number }}</Link>
                        </td>
                        <td class="px-3 py-2 text-end whitespace-nowrap">
                            <template v-if="d.status === 'pending'">
                                <Link v-if="$can('purchases.drafts.complete')" :href="route('purchases.drafts.complete-form', d.id)" class="px-3 py-1 rounded-lg bg-indigo-600 text-white font-bold hover:bg-indigo-700" data-draft-complete>{{ $t('pdraft.complete') }}</Link>
                                <RowActions v-if="$can('purchases.drafts.delete')" :remove="route('purchases.drafts.destroy', d.id)" :confirm="$t('pdraft.confirm_discard', { number: d.number })" />
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!drafts.data.length"><td colspan="7" class="px-4 py-8 text-center text-slate-400">{{ $t('pdraft.empty') }}</td></tr>
                </tbody>
            </table>
            <Pagination :links="drafts.links" />
        </div>
    </AppLayout>
</template>

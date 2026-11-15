<script setup>
import { useI18n } from 'vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';
const { t } = useI18n();

defineProps({ rows: Array, days: Number });
const restore = (r) => router.post(route('recycle-bin.restore', [r.type, r.id]), {}, { preserveScroll: true });
const purge = (r) => { if (confirm(t('x_recyclebin_index.permanent_deletion_cannot_be_und'))) router.delete(route('recycle-bin.purge', [r.type, r.id]), { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_recyclebin_index.recycle_bin')" />
        <PageHeader :title="$t('x_recyclebin_index.recycle_bin')" :subtitle="$t('x_recyclebin_index.deleted_documents_are_kept_for_n', { n0: days })" :crumbs="[{ label: $t('x_recyclebin_index.settings') }, { label: $t('x_recyclebin_index.recycle_bin') }]" />
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('x_recyclebin_index.type') }}</th><th class="text-start px-4 py-2">{{ $t('x_recyclebin_index.number') }}</th><th class="text-start px-4 py-2">{{ $t('x_recyclebin_index.party') }}</th><th class="px-4 py-2 text-end">{{ $t('x_recyclebin_index.total') }}</th><th class="px-4 py-2">{{ $t('x_recyclebin_index.deletion_date') }}</th><th class="px-4 py-2">{{ $t('x_recyclebin_index.purged_on') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.type + r.id" class="border-t border-slate-100">
                        <td class="px-4 py-2">{{ r.kind }}</td>
                        <td class="px-4 py-2 font-mono">{{ r.number }}</td>
                        <td class="px-4 py-2">{{ r.party || '—' }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ Number(r.total).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(r.deleted_at) }}</td>
                        <td class="px-4 py-2 text-center font-mono text-slate-500">{{ date(r.expires_at) }}</td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            <button v-if="$can('admin.recycle_bin.manage')" @click="restore(r)" class="text-emerald-600 font-bold me-3">{{ $t('x_recyclebin_index.restore') }}</button>
                            <button v-if="$can('admin.recycle_bin.manage')" @click="purge(r)" class="text-rose-600 font-bold">{{ $t('x_recyclebin_index.delete_permanently') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('x_recyclebin_index.the_cart_is_empty') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

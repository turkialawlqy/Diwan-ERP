<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

defineProps({ portal: Object, statement: Object, pdfUrl: String });
</script>

<template>
    <Head :title="$t('por_portal_statement.statement')" />
    <PortalLayout :portal="portal" active="statement">
        <div class="flex items-center justify-between mb-3"><h1 class="text-lg font-black text-slate-900">{{ $t('por_portal_statement.statement') }}</h1><a :href="pdfUrl" target="_blank" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('por_portal_statement.download_pdf') }}</a></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-statement>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('por_portal_statement.date') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_statement.description') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_statement.reference') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_statement.debit') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_statement.credit') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_statement.balance') }}</th></tr></thead>
                <tbody>
                    <tr v-for="(t, i) in statement.transactions" :key="i" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ t.date }}</td><td class="px-4 py-2">{{ t.type }}</td><td class="px-4 py-2 font-mono">{{ t.reference }}</td><td class="px-4 py-2 font-mono">{{ t.debit ? money(t.debit) : '' }}</td><td class="px-4 py-2 font-mono">{{ t.credit ? money(t.credit) : '' }}</td><td class="px-4 py-2 font-mono font-bold">{{ t.balance != null ? money(t.balance) : '' }}</td></tr>
                </tbody>
                <tfoot class="border-t border-slate-200 font-bold"><tr><td colspan="5" class="px-4 py-2 text-start">{{ $t('por_portal_statement.closing_balance') }}</td><td class="px-4 py-2 font-mono">{{ money(statement.closing) }}</td></tr></tfoot>
            </table>
        </div>
    </PortalLayout>
</template>

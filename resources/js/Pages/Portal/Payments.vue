<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money } from '@/utils/format';
const { t } = useI18n();

defineProps({ portal: Object, payments: Object });
const method = computed(() => ({ cash: t('por_portal_payments.cash'), card: t('por_portal_payments.card'), bank_transfer: t('por_portal_payments.bank_transfer'), cheque: t('por_portal_payments.cheque') }));
</script>

<template>
    <Head :title="$t('por_portal_payments.payments')" />
    <PortalLayout :portal="portal" active="payments">
        <h1 class="text-lg font-black text-slate-900 mb-3">{{ $t('por_portal_payments.payments_made') }}</h1>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('por_portal_payments.voucher') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_payments.date') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_payments.method') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_payments.amount') }}</th></tr></thead>
                <tbody>
                    <tr v-for="p in payments.data" :key="p.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono font-bold">{{ p.payment_number }}</td><td class="px-4 py-2 font-mono">{{ p.payment_date?.slice(0, 10) }}</td><td class="px-4 py-2">{{ method[p.payment_method] || p.payment_method }}</td><td class="px-4 py-2 font-mono">{{ money(p.amount) }}</td></tr>
                    <tr v-if="!payments.data.length"><td colspan="4" class="py-8 text-center text-slate-400">{{ $t('por_portal_payments.no_payments') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="payments" />
    </PortalLayout>
</template>

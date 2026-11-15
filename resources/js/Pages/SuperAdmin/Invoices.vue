<script setup>
import { useI18n } from 'vue-i18n';
import { ask } from '@/composables/useDialog';
import { Head, Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money, date } from '@/utils/format';
const { t } = useI18n();

defineProps({ invoices: Object, status: String });
const confirmPay = async (i) => {
    const ref = await ask(t('z_superadmin_invoices.bank_transfer_reference'), { title: t('z_superadmin_invoices.confirm_the_invoice_payment'), confirmText: t('z_superadmin_invoices.confirm_payment') });
    if (ref !== null) router.post(route('super-admin.invoices.confirm', i.id), { payment_reference: ref }, { preserveScroll: true });
};
const voidIt = (i) => { if (confirm(t('z_superadmin_invoices.cancel_the_invoice'))) router.post(route('super-admin.invoices.void', i.id), {}, { preserveScroll: true }); };
</script>

<template>
    <SuperAdminLayout>
        <Head :title="$t('z_superadmin_invoices.subscription_invoices')" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 text-xs">
            <div class="flex gap-2 mb-3"><Link v-for="s in ['unpaid', 'paid', 'void', 'all']" :key="s" :href="route('super-admin.invoices', { status: s })" :class="status === s ? 'bg-slate-900 text-white' : 'bg-white border'" class="px-3 py-1.5 rounded-xl font-bold">{{ { unpaid: $t('z_superadmin_invoices.unpaid'), paid: $t('z_superadmin_invoices.paid'), void: $t('z_superadmin_invoices.cancelled'), all: $t('z_superadmin_invoices.all') }[s] }}</Link></div>
            <div class="bg-white rounded-2xl border overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('z_superadmin_invoices.invoice') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_invoices.company') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_invoices.plan') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_invoices.period') }}</th><th class="px-3 py-2 text-end">{{ $t('z_superadmin_invoices.total') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_invoices.status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="i in invoices.data" :key="i.id" class="border-t">
                            <td class="px-3 py-2 font-mono"><a :href="route('super-admin.invoices.show', i.id)" target="_blank" class="text-indigo-600 hover:underline">{{ i.number }}</a></td><td class="px-3 py-2 text-center"><Link :href="route('super-admin.companies.show', i.company_id)" class="text-indigo-600">{{ i.company?.name }}</Link></td>
                            <td class="px-3 py-2 text-center">{{ i.plan?.name_ar }} ({{ i.billing_cycle }})</td><td class="px-3 py-2 text-center font-mono">{{ date(i.period_start) }} → {{ date(i.period_end) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(i.total) }}</td><td class="px-3 py-2 text-center">{{ i.status }}</td>
                            <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="i.status === 'unpaid'"><button @click="confirmPay(i)" class="text-emerald-600 font-bold me-2">{{ $t('z_superadmin_invoices.confirm_payment') }}</button><button @click="voidIt(i)" class="text-rose-600 font-bold">{{ $t('z_superadmin_invoices.cancel') }}</button></template></td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :paginator="invoices" />
            </div>
        </div>
    </SuperAdminLayout>
</template>

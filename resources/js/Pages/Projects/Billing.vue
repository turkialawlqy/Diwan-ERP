<script setup>
import { useI18n } from 'vue-i18n';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, qty } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ project: Object, billing: Object, company: Object });
const approve = () => { if (confirm(t('prj_projects_billing.approve_the_certificate_and_issu'))) router.post(route('projects.billings.approve', [props.project.id, props.billing.id])); };
const remove = () => { if (confirm(t('prj_projects_billing.delete_the_draft_certificate'))) router.delete(route('projects.billings.destroy', [props.project.id, props.billing.id])); };
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="$t('prj_projects_billing.certificate_n', { n0: billing.number })" />
        <PageHeader :title="$t('prj_projects_billing.certificate_no_n_n', { n0: billing.sequence, n1: billing.number })" :subtitle="`${project.code} ${project.name}`" :crumbs="[{ label: $t('prj_projects_billing.projects'), href: route('projects.index') }, { label: project.code, href: route('projects.show', project.id) }, { label: billing.number }]">
            <template #actions>
                <StatusBadge :status="billing.status" />
                <template v-if="billing.status === 'draft'">
                    <button v-if="$can('projects.billings.approve')" @click="approve" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('prj_projects_billing.approve_and_issue_the_invoice') }}</button>
                    <button v-if="$can('projects.billings.delete')" @click="remove" class="px-3 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">{{ $t('prj_projects_billing.delete') }}</button>
                </template>
                <Link v-if="billing.invoice" :href="route('sales.invoices.show', billing.invoice.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('prj_projects_billing.invoice_n', { n0: billing.invoice.invoice_number }) }}</Link>
                <ShareDocument v-if="$can('projects.billings.print')" type="billing" :id="billing.id" />
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('prj_projects_billing.print') }}</button>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 text-xs space-y-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><div class="text-slate-400">{{ $t('prj_projects_billing.contractor') }}</div><b>{{ company?.name }}</b> <span class="font-mono text-slate-400">{{ company?.tax_number }}</span></div>
                <div><div class="text-slate-400">{{ $t('prj_projects_billing.owner') }}</div><b>{{ project.customer?.name }}</b></div>
                <div><div class="text-slate-400">{{ $t('prj_projects_billing.contract_number') }}</div><b>{{ project.contract_number || '—' }}</b></div>
                <div><div class="text-slate-400">{{ $t('prj_projects_billing.period') }}</div><b class="font-mono">{{ date(billing.period_from) }} → {{ date(billing.period_to) }}</b></div>
            </div>
            <div class="overflow-x-auto"><table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-2 py-2">{{ $t('prj_projects_billing.item') }}</th><th class="text-start px-2 py-2">{{ $t('prj_projects_billing.description') }}</th><th class="px-2 py-2">{{ $t('prj_projects_billing.unit') }}</th><th class="px-2 py-2">{{ $t('prj_projects_billing.previous_quantity') }}</th><th class="px-2 py-2">{{ $t('prj_projects_billing.period_quantity') }}</th><th class="px-2 py-2">{{ $t('prj_projects_billing.cumulative') }}</th><th class="px-2 py-2 text-end">{{ $t('prj_projects_billing.unit_price') }}</th><th class="px-2 py-2 text-end">{{ $t('prj_projects_billing.period_value') }}</th></tr></thead>
                <tbody>
                    <tr v-for="i in billing.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-2 py-1.5 font-mono">{{ i.boq_item?.code }}</td><td class="px-2 py-1.5">{{ i.boq_item?.description }}</td><td class="px-2 py-1.5 text-center">{{ i.boq_item?.unit }}</td>
                        <td class="px-2 py-1.5 text-center font-mono">{{ qty(i.previous_quantity) }}</td><td class="px-2 py-1.5 text-center font-mono">{{ qty(i.current_quantity) }}</td>
                        <td class="px-2 py-1.5 text-center font-mono">{{ qty(Number(i.previous_quantity) + Number(i.current_quantity)) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.unit_price) }}</td><td class="px-2 py-1.5 text-end font-mono">{{ money(i.amount) }}</td>
                    </tr>
                </tbody>
            </table></div>
            <div class="flex justify-end">
                <dl class="w-80 space-y-1">
                    <div class="flex justify-between"><dt>{{ $t('prj_projects_billing.cumulative_work_total') }}</dt><dd class="font-mono">{{ money(billing.cumulative_value) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ $t('prj_projects_billing.less_previous_certificates') }}</dt><dd class="font-mono">({{ money(billing.previous_value) }})</dd></div>
                    <div class="flex justify-between font-bold"><dt>{{ $t('prj_projects_billing.this_period_s_work') }}</dt><dd class="font-mono">{{ money(billing.current_value) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ $t('prj_projects_billing.less_advance_payment_recovery') }}</dt><dd class="font-mono">({{ money(billing.advance_recovery) }})</dd></div>
                    <div class="flex justify-between"><dt>{{ $t('prj_projects_billing.taxable_amount') }}</dt><dd class="font-mono">{{ money(billing.taxable_amount) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ $taxLabel() }}</dt><dd class="font-mono">{{ money(billing.vat_amount) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ $t('prj_projects_billing.less_retention') }}</dt><dd class="font-mono">({{ money(billing.retention) }})</dd></div>
                    <div class="flex justify-between border-t pt-1 font-black text-sm"><dt>{{ $t('prj_projects_billing.net_due') }}</dt><dd class="font-mono">{{ money(billing.net_payable) }}</dd></div>
                </dl>
            </div>
            <p v-if="billing.invoice" class="text-slate-500">{{ $t('prj_projects_billing.tax_invoice_n_e_invoicing_status', { n0: billing.invoice.invoice_number, n1: billing.invoice.zatca_status }) }}</p>
        </div>
    </AppLayout>
</template>

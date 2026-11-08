<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { taxRate } from '@/utils/tax';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import BoqEditor from '@/Components/Projects/BoqEditor.vue';
import { money, date, qty, today } from '@/utils/format';

const props = defineProps({ project: Object, summary: Object, wip: { type: Object, default: null }, boq: Array, billings: Array, variations: Array, subcontracts: Array, costs: Array, guarantees: Array, dailyReports: Array, bankAccounts: Array, warehouses: Array });
const tab = ref('overview');
const tabs = computed(() => [['overview', t('projshow.tab_overview')], ['boq', t('projshow.tab_boq')], ['billings', t('projshow.tab_billings')], ['variations', t('projshow.tab_variations')], ['subcontracts', t('projshow.tab_subs')], ['costs', t('projshow.tab_costs')], ['guarantees', t('projshow.tab_guarantees')], ['daily', t('projshow.tab_daily')]]);
const categoryLabel = computed(() => ({ material: t('projshow.cat_material'), labour: t('projshow.cat_labour'), equipment: t('projshow.cat_equipment'), subcontract: t('projshow.cat_subcontract'), other: t('projshow.cat_other') }));
const statusLabel = computed(() => ({ planning: t('projshow.st_planning'), active: t('projshow.st_active'), on_hold: t('projshow.st_on_hold'), completed: t('projshow.st_completed'), closed: t('projshow.st_closed') }));
const dialog = ref(null);
const close = () => (dialog.value = null);
const opts = { preserveScroll: true, onSuccess: close };

// BOQ
const boqForm = useForm({ boq: props.boq.filter((b) => !b.variation_id).map((b) => ({ code: b.code, parent_code: props.boq.find((p) => p.id === b.parent_id)?.code || null, description: b.description, unit: b.unit, quantity: Number(b.quantity), unit_price: Number(b.unit_price), unit_cost: Number(b.unit_cost), is_group: b.is_group })) });
const saveBoq = () => boqForm.put(route('projects.boq.update', props.project.id), { preserveScroll: true });
const billable = computed(() => props.boq.filter((b) => !b.is_group && Number(b.quantity) > 0));

// Billing
const billingForm = useForm({ billing_date: today(), period_from: '', period_to: today(), notes: '', lines: [] });
const openBilling = () => { billingForm.lines = billable.value.map((b) => ({ boq_item_id: b.id, code: b.code, description: b.description, contract: Number(b.quantity), billed: Number(b.billed_quantity), price: Number(b.unit_price), current_quantity: 0 })); dialog.value = 'billing'; };
const billingValue = computed(() => billingForm.lines.reduce((s, l) => s + Number(l.current_quantity || 0) * l.price, 0));
const saveBilling = () => billingForm.transform((d) => ({ ...d, lines: d.lines.filter((l) => Number(l.current_quantity) > 0).map(({ boq_item_id, current_quantity }) => ({ boq_item_id, current_quantity })) })).post(route('projects.billings.store', props.project.id));

// Advance / retention
const advanceForm = useForm({ date: today(), amount: 0, payment_method: 'bank_transfer', bank_account_id: '' });
const retentionForm = useForm({ date: today(), amount: props.summary.retention_open });

// Variations
const variationForm = useForm({ date: today(), description: '', amount: 0, cost_impact: 0, time_impact_days: 0 });
const variationAct = (v, action) => router.post(route(`projects.variations.${action}`, [props.project.id, v.id]), {}, { preserveScroll: true });

// Subcontracts
const subForm = useForm({ supplier_id: null, scope: '', contract_value: 0, retention_percent: 10, start_date: '', end_date: '' });
const selectedSub = ref(null);
const subAdvanceForm = useForm({ date: today(), amount: 0, payment_method: 'bank_transfer', bank_account_id: '' });
const releaseForm = useForm({ date: today(), amount: 0 });
// Revenue by progress: executed quantities per BOQ line and the WIP adjustment for a period end.
const progressForm = useForm({ lines: [] });
const wipForm = useForm({ date: today() });
const openProgress = () => { progressForm.lines = billable.value.map((b) => ({ boq_item_id: b.id, code: b.code, description: b.description, contract: Number(b.quantity), billed: Number(b.billed_quantity), executed_quantity: Number(b.executed_quantity || 0) })); dialog.value = 'progress'; };
const saveProgress = () => progressForm.transform((d) => ({ lines: d.lines.map(({ boq_item_id, executed_quantity }) => ({ boq_item_id, executed_quantity: Number(executed_quantity) || 0 })) })).put(route('projects.progress.update', props.project.id), opts);
const certifyForm = useForm({ date: today(), cumulative_value: 0, vat_rate: taxRate(), supplier_invoice_number: '' });
const openSub = (s, kind) => { selectedSub.value = s; certifyForm.cumulative_value = Number(s.certified_to_date); dialog.value = kind; };

// Costs
const costForm = useForm({ date: today(), category: 'labour', description: '', amount: 0, vat_amount: 0, paid_from: 'accrued', supplier_id: null, bank_account_id: '', reference: '' });
const materialForm = useForm({ direction: 'issue', date: today(), warehouse_id: props.project.warehouse_id || '', reference: '', lines: [] });
const lookup = ref('');
const results = ref([]);
const searchProducts = async () => { results.value = lookup.value.length < 2 ? [] : (await axios.get(route('lookup.products'), { params: { q: lookup.value } })).data.data; };
const addMaterial = (p) => { materialForm.lines.push({ product_id: p.id, name: p.name_ar, quantity: 1 }); results.value = []; lookup.value = ''; };

// Daily report & project details
const dailyForm = useForm({ date: today(), weather: '', manpower: 0, equipment: '', work_done: '', issues: '' });
const editForm = useForm({ name: props.project.name, status: props.project.status, manager_id: props.project.manager_id, warehouse_id: props.project.warehouse_id, start_date: props.project.start_date?.substring(0, 10), end_date: props.project.end_date?.substring(0, 10), advance_percent: props.project.advance_percent, retention_percent: props.project.retention_percent, location: props.project.location });
</script>

<template>
    <AppLayout>
        <Head :title="project.name" />
        <PageHeader :title="`${project.code} — ${project.name}`" :subtitle="$t('projshow.subtitle_cc', { customer: project.customer?.name, status: statusLabel[project.status], cc: project.cost_center?.code })" :crumbs="[{ label: $t('projshow.c_contracting') }, { label: $t('projshow.c_projects'), href: route('projects.index') }, { label: project.code }]">
            <template #actions>
                <button v-if="$can('projects.finance.create')" @click="dialog = 'advance'" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('projshow.advance_payment') }}</button>
                <button v-if="$can('projects.billings.create') && billable.length" @click="openBilling" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.new_progress_billing') }}</button>
                <button v-if="$can('projects.projects.edit')" @click="dialog = 'edit'" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('projshow.edit') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('projshow.contract_value')" :value="money(summary.contract_value)" />
            <StatCard :label="$t('projshow.billed_completed_work')" :value="`${money(summary.billed_to_date)} (${summary.progress_percent}%)`" />
            <StatCard :label="$t('projshow.actual_estimated_cost')" :value="`${money(summary.actual_cost)} / ${money(summary.estimated_cost)}`" />
            <StatCard :label="$t('projshow.gross_profit')" :value="`${money(summary.gross_profit)}${summary.margin_percent !== null ? ` (${summary.margin_percent}%)` : ''}`" />
        </div>

        <div class="flex flex-wrap gap-1 mb-3 border-b border-slate-200">
            <button v-for="[key, label] in tabs" :key="key" @click="tab = key" :class="tab === key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500'" class="px-3 py-2 text-xs font-bold border-b-2">{{ label }}</button>
        </div>

        <!-- Overview -->
        <div v-if="tab === 'overview'" class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('projshow.contract') }}</h3>
                <div class="flex justify-between"><span>{{ $t('projshow.approved_variation_orders') }}</span><b class="font-mono">{{ money(summary.approved_variations) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.remaining_to_bill') }}</span><b class="font-mono">{{ money(summary.remaining_to_bill) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.expected_margin_per_boq') }}</span><b class="font-mono">{{ summary.projected_margin ?? '—' }}%</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.period') }}</span><b class="font-mono">{{ date(project.start_date) }} → {{ date(project.end_date) }}</b></div>
            </div>
            <div v-if="wip" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5" data-wip>
                <h3 class="font-black text-slate-700 mb-2">{{ $t('projshow.revenue_by_progress_wip') }}</h3>
                <div class="flex justify-between"><span>{{ $t('projshow.progress_bill_of_quantities') }}</span><b class="font-mono">{{ wip.percent }}%</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.earned_revenue') }}</span><b class="font-mono">{{ money(wip.earned) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.approved_billed') }}</span><b class="font-mono">{{ money(wip.billed) }}</b></div>
                <div class="flex justify-between"><span>{{ wip.wip >= 0 ? $t('projshow.wip_asset') : $t('projshow.wip_liability') }}</span><b class="font-mono" :class="wip.wip >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(Math.abs(wip.wip)) }}</b></div>
                <div class="flex gap-2 pt-2">
                    <button v-if="$can('projects.boq.edit')" @click="openProgress" data-open-progress class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">{{ $t('projshow.update_progress') }}</button>
                    <button v-if="$can('projects.finance.approve')" @click="dialog = 'wip'" data-open-wip class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('projshow.post_wip_adjustment') }}</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('projshow.advance_and_retention') }}</h3>
                <div class="flex justify-between"><span>{{ $t('projshow.advance_received') }}</span><b class="font-mono">{{ money(project.advance_received) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.advance_not_yet_recovered') }}</span><b class="font-mono">{{ money(summary.advance_open) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.retention_held') }}</span><b class="font-mono">{{ money(summary.retention_open) }}</b></div>
                <div class="flex justify-between"><span>{{ $t('projshow.collected') }}</span><b class="font-mono">{{ money(summary.collected) }}</b></div>
                <button v-if="summary.retention_open > 0 && $can('projects.finance.approve')" @click="dialog = 'retention'" class="mt-2 text-indigo-600 font-bold">{{ $t('projshow.release_retention') }}</button>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('projshow.costs_by_type') }}</h3>
                <div v-for="(label, key) in categoryLabel" :key="key" class="flex justify-between"><span>{{ label }}</span><b class="font-mono">{{ money(summary.costs_by_category[key] || 0) }}</b></div>
                <div class="flex justify-between border-t pt-1"><span>{{ $t('projshow.cost_variance_estimate_actual') }}</span><b class="font-mono" :class="summary.cost_variance < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ money(summary.cost_variance) }}</b></div>
            </div>
        </div>

        <!-- BOQ -->
        <div v-if="tab === 'boq'" class="bg-white border border-slate-200 rounded-2xl p-4">
            <template v-if="!billings.length && $can('projects.boq.edit')">
                <BoqEditor v-model="boqForm.boq" />
                <p v-for="(msg, key) in boqForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
                <div class="flex justify-end mt-3"><button @click="saveBoq" :disabled="boqForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.save_the_bill_of_quantities') }}</button></div>
            </template>
            <div class="overflow-x-auto" v-else><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('projshow.code') }}</th><th class="text-start px-3 py-2">{{ $t('projshow.description') }}</th><th class="px-3 py-2">{{ $t('projshow.unit') }}</th><th class="px-3 py-2">{{ $t('projshow.quantity') }}</th><th class="px-3 py-2">{{ $t('projshow.executed') }}</th><th class="px-3 py-2">%</th><th class="px-3 py-2 text-end">{{ $t('projshow.unit_price') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.value') }}</th></tr></thead>
                <tbody>
                    <tr v-for="b in boq" :key="b.id" class="border-t border-slate-100" :class="b.is_group ? 'bg-slate-50 font-bold' : ''">
                        <td class="px-3 py-1.5 font-mono">{{ b.code }}</td><td class="px-3 py-1.5">{{ b.description }}</td>
                        <template v-if="!b.is_group">
                            <td class="px-3 py-1.5 text-center">{{ b.unit }}</td><td class="px-3 py-1.5 text-center font-mono">{{ qty(b.quantity) }}</td><td class="px-3 py-1.5 text-center font-mono">{{ qty(b.billed_quantity) }}</td>
                            <td class="px-3 py-1.5 text-center font-mono">{{ Number(b.quantity) ? Math.round(b.billed_quantity / b.quantity * 100) : 0 }}%</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ money(b.unit_price) }}</td><td class="px-3 py-1.5 text-end font-mono">{{ money(b.quantity * b.unit_price) }}</td>
                        </template>
                        <td v-else colspan="6"></td>
                    </tr>
                </tbody>
            </table></div>
        </div>

        <!-- Billings -->
        <div v-if="tab === 'billings'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('projshow.billing') }}</th><th class="px-3 py-2">{{ $t('projshow.date') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.period_works') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.advance_recovery') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.retention') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.tax') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.net_payable') }}</th><th class="px-3 py-2">{{ $t('projshow.status') }}</th><th class="px-3 py-2">{{ $t('projshow.invoice') }}</th></tr></thead>
                <tbody>
                    <tr v-for="b in billings" :key="b.id" class="border-t border-slate-100">
                        <td class="px-3 py-2"><Link :href="route('projects.billings.show', [project.id, b.id])" class="text-indigo-600 font-mono font-bold">{{ b.number }}</Link></td>
                        <td class="px-3 py-2 text-center font-mono">{{ date(b.billing_date) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.current_value) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.advance_recovery) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.retention) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.vat_amount) }}</td>
                        <td class="px-3 py-2 text-end font-mono font-bold">{{ money(b.net_payable) }}</td>
                        <td class="px-3 py-2 text-center"><StatusBadge :status="b.status" /></td>
                        <td class="px-3 py-2 text-center"><Link v-if="b.invoice" :href="route('sales.invoices.show', b.invoice.id)" class="text-indigo-600 font-mono">{{ b.invoice.invoice_number }}</Link></td>
                    </tr>
                    <tr v-if="!billings.length"><td colspan="9" class="py-8 text-center text-slate-400">{{ $t('projshow.no_progress_billings') }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Variations -->
        <div v-if="tab === 'variations'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="p-3 border-b border-slate-100 text-end"><button v-if="$can('projects.variations.create')" @click="dialog = 'variation'" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.variation_order') }}</button></div>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('projshow.number') }}</th><th class="px-3 py-2">{{ $t('projshow.date') }}</th><th class="text-start px-3 py-2">{{ $t('projshow.description') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.value') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.cost_impact') }}</th><th class="px-3 py-2">{{ $t('projshow.days') }}</th><th class="px-3 py-2">{{ $t('projshow.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="v in variations" :key="v.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ v.number }}</td><td class="px-3 py-2 text-center font-mono">{{ date(v.date) }}</td><td class="px-3 py-2">{{ v.description }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(v.amount) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(v.cost_impact) }}</td><td class="px-3 py-2 text-center">{{ v.time_impact_days }}</td>
                        <td class="px-3 py-2 text-center"><StatusBadge :status="v.status" /></td>
                        <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="v.status === 'draft' && $can('projects.variations.approve')"><button @click="variationAct(v, 'approve')" class="text-emerald-600 font-bold me-2">{{ $t('projshow.approve') }}</button><button @click="variationAct(v, 'reject')" class="text-rose-600 font-bold">{{ $t('projshow.reject') }}</button></template></td>
                    </tr>
                    <tr v-if="!variations.length"><td colspan="8" class="py-8 text-center text-slate-400">{{ $t('projshow.no_variation_orders') }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Subcontracts -->
        <div v-if="tab === 'subcontracts'" class="space-y-3">
            <div class="text-end"><button v-if="$can('projects.subcontracts.create')" @click="dialog = 'subcontract'" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.subcontract') }}</button></div>
            <div v-for="s in subcontracts" :key="s.id" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <div class="flex flex-wrap justify-between gap-2">
                    <div><b class="font-mono">{{ s.number }}</b> — {{ s.supplier?.name }} <div class="text-slate-500">{{ s.scope }}</div></div>
                    <div class="flex gap-2 h-fit" v-if="$can('projects.subcontracts.approve')">
                        <button @click="openSub(s, 'subAdvance')" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">{{ $t('projshow.advance_payment') }}</button>
                        <button v-if="Number(s.retention_held) - Number(s.retention_released || 0) > 0.004" @click="() => { releaseForm.amount = Number(s.retention_held) - Number(s.retention_released || 0); openSub(s, 'releaseSub'); }" data-release-sub-retention class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">{{ $t('projshow.retention_release') }}</button>
                        <button @click="openSub(s, 'certify')" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('projshow.billing_2') }}</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mt-3">
                    <div>{{ $t('projshow.contract_2') }} <b class="font-mono">{{ money(s.contract_value) }}</b></div>
                    <div>{{ $t('projshow.approved') }} <b class="font-mono">{{ money(s.certified_to_date) }}</b></div>
                    <div>{{ $t('projshow.advance') }} <b class="font-mono">{{ money(s.advance_paid) }}</b> {{ $t('projshow.recovered') }} <b class="font-mono">{{ money(s.advance_recovered) }}</b></div>
                    <div>{{ $t('projshow.retention_2') }} <b class="font-mono">{{ money(s.retention_held) }}</b> ({{ s.retention_percent }}%)</div>
                    <div>{{ $t('projshow.billings') }} <b>{{ s.certificates.length }}</b></div>
                </div>
                <div class="overflow-x-auto" v-if="s.certificates.length"><table class="w-full mt-3">
                    <tr v-for="c in s.certificates" :key="c.id" class="border-t border-slate-100"><td class="py-1 font-mono">{{ c.number }}</td><td class="font-mono">{{ date(c.date) }}</td><td class="text-end font-mono">{{ money(c.current_value) }}</td><td class="text-end font-mono">{{ $t('projshow.net_n', { n: money(c.net_payable) }) }}</td></tr>
                </table></div>
            </div>
            <p v-if="!subcontracts.length" class="text-xs text-slate-400 text-center py-8">{{ $t('projshow.no_subcontracts') }}</p>
        </div>

        <!-- Costs -->
        <div v-if="tab === 'costs'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="p-3 border-b border-slate-100 flex justify-end gap-2" v-if="$can('projects.costs.create')">
                <button @click="materialForm.direction = 'issue'; dialog = 'materials'" class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('projshow.issue_materials') }}</button>
                <button @click="materialForm.direction = 'return'; dialog = 'materials'" class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('projshow.return_materials') }}</button>
                <button @click="dialog = 'cost'" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.direct_cost') }}</button>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">{{ $t('projshow.date') }}</th><th class="px-3 py-2">{{ $t('projshow.type') }}</th><th class="text-start px-3 py-2">{{ $t('projshow.statement') }}</th><th class="px-3 py-2">{{ $t('projshow.quantity') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.amount') }}</th></tr></thead>
                <tbody>
                    <tr v-for="c in costs" :key="c.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 text-center font-mono">{{ date(c.date) }}</td><td class="px-3 py-2 text-center">{{ categoryLabel[c.category] }}</td><td class="px-3 py-2">{{ c.description }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ Number(c.quantity) ? qty(c.quantity) : '' }}</td><td class="px-3 py-2 text-end font-mono" :class="c.amount < 0 ? 'text-rose-600' : ''">{{ money(c.amount) }}</td>
                    </tr>
                    <tr v-if="!costs.length"><td colspan="5" class="py-8 text-center text-slate-400">{{ $t('projshow.no_costs') }}</td></tr>
                </tbody>
            </table></div>
        </div>

        <!-- Guarantees -->
        <div v-if="tab === 'guarantees'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('projshow.number') }}</th><th class="px-3 py-2">{{ $t('projshow.type') }}</th><th class="px-3 py-2">{{ $t('projshow.bank') }}</th><th class="px-3 py-2 text-end">{{ $t('projshow.amount') }}</th><th class="px-3 py-2">{{ $t('projshow.expiry') }}</th><th class="px-3 py-2">{{ $t('projshow.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="g in guarantees" :key="g.id" class="border-t border-slate-100"><td class="px-3 py-2 font-mono">{{ g.number }}</td><td class="px-3 py-2 text-center">{{ g.type }}</td><td class="px-3 py-2 text-center">{{ g.bank_name }}</td><td class="px-3 py-2 text-end font-mono">{{ money(g.amount) }}</td><td class="px-3 py-2 text-center font-mono">{{ date(g.expiry_date) }}</td><td class="px-3 py-2 text-center">{{ g.status }}</td></tr>
                    <tr v-if="!guarantees.length"><td colspan="6" class="py-8 text-center text-slate-400">{{ $t('projshow.no_letters_of_guarantee_managed') }} <Link :href="route('projects.guarantees.index')" class="text-indigo-600">{{ $t('projshow.letters_of_guarantee') }}</Link></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Daily reports -->
        <div v-if="tab === 'daily'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div v-if="$can('projects.daily_reports.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <Field :label="$t('projshow.date')"><DatePicker v-model="dailyForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="grid grid-cols-2 gap-2">
                    <Field :label="$t('projshow.weather')"><input v-model="dailyForm.weather" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('projshow.labour_2')"><input v-model.number="dailyForm.manpower" type="text" inputmode="decimal" data-num min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <Field :label="$t('projshow.equipment_2')"><input v-model="dailyForm.equipment" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.work_done')" :error="dailyForm.errors.work_done"><textarea v-model="dailyForm.work_done" rows="3" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                <Field :label="$t('projshow.obstacles')"><textarea v-model="dailyForm.issues" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                <button @click="dailyForm.post(route('projects.daily-reports.store', project.id), { preserveScroll: true, onSuccess: () => dailyForm.reset('work_done', 'issues') })" class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.save_the_report') }}</button>
            </div>
            <div class="md:col-span-2 space-y-2">
                <div v-for="r in dailyReports" :key="r.id" class="bg-white border border-slate-200 rounded-2xl p-3 text-xs">
                    <div class="flex justify-between text-slate-500"><b class="font-mono text-slate-800">{{ date(r.date) }}</b><span>{{ $t('projshow.weather_line', { weather: r.weather, n: r.manpower, author: r.author?.name }) }}</span></div>
                    <p class="mt-1 whitespace-pre-line">{{ r.work_done }}</p>
                    <p v-if="r.issues" class="mt-1 text-rose-600 whitespace-pre-line">{{ r.issues }}</p>
                </div>
            </div>
        </div>

        <!-- Dialogs -->
        <Dialog :show="dialog === 'billing'" :title="$t('projshow.new_progress_billing')" size="xl" @close="close">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field :label="$t('projshow.billing_date')"><DatePicker v-model="billingForm.billing_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.period_from')"><DatePicker v-model="billingForm.period_from" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.to')"><DatePicker v-model="billingForm.period_to" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-xs">
                    <tr class="text-slate-500"><th class="text-start">{{ $t('projshow.item') }}</th><th>{{ $t('projshow.contract_quantity') }}</th><th>{{ $t('projshow.previously_executed') }}</th><th class="w-28">{{ $t('projshow.period_quantity') }}</th><th class="text-end">{{ $t('projshow.value') }}</th></tr>
                    <tr v-for="l in billingForm.lines" :key="l.boq_item_id" class="border-t border-slate-100">
                        <td class="py-1.5"><span class="font-mono">{{ l.code }}</span> {{ l.description }}</td>
                        <td class="text-center font-mono">{{ qty(l.contract) }}</td><td class="text-center font-mono">{{ qty(l.billed) }}</td>
                        <td><input v-model.number="l.current_quantity" type="text" inputmode="decimal" data-num min="0" :max="l.contract - l.billed" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="text-end font-mono">{{ money(l.current_quantity * l.price) }}</td>
                    </tr>
                </table>
            </div>
            <p class="text-xs mt-2">{{ $t('projshow.value_of_period_works') }} <b class="font-mono">{{ money(billingValue) }}</b> {{ $t('projshow.billing_note', { adv: project.advance_percent, ret: project.retention_percent }) }}</p>
            <p v-for="(msg, key) in billingForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <template #footer><button @click="saveBilling" :disabled="billingForm.processing || billingValue <= 0" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('projshow.save_as_draft') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'advance'" :title="$t('projshow.advance_payment_from_the_custome')" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.date')"><DatePicker v-model="advanceForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.amount_before_tax')" :error="advanceForm.errors.amount"><input v-model.number="advanceForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.receiving_method')"><select v-model="advanceForm.payment_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">{{ $t('projshow.bank_transfer') }}</option><option value="cheque">{{ $t('projshow.cheque') }}</option><option value="cash">{{ $t('projshow.cash') }}</option></select></Field>
                <Field :label="$t('projshow.account')"><SelectCreate v-model="advanceForm.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('projshow.default')" /></Field>
            </div>
            <p class="text-[11px] text-slate-500 mt-2">{{ $t('projshow.advance_note', { adv: project.advance_percent }) }}</p>
            <template #footer><button @click="advanceForm.post(route('projects.advance', project.id), opts)" :disabled="advanceForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.record') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'retention'" :title="$t('projshow.release_retention')" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.date')"><DatePicker v-model="retentionForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.amount')" :error="retentionForm.errors.amount"><input v-model.number="retentionForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="retentionForm.post(route('projects.retention.release', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.release') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'variation'" :title="$t('projshow.variation_order')" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.description')" :error="variationForm.errors.description" class="col-span-2"><input v-model="variationForm.description" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.date')"><DatePicker v-model="variationForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.value_negative_to_remove')"><input v-model.number="variationForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.cost_impact')"><input v-model.number="variationForm.cost_impact" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.duration_extension_days')"><input v-model.number="variationForm.time_impact_days" type="text" inputmode="decimal" data-num min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="variationForm.post(route('projects.variations.store', project.id), { ...opts, onSuccess: () => { close(); variationForm.reset(); } })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.save') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'subcontract'" :title="$t('projshow.subcontract_2')" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.contractor_supplier')" :error="subForm.errors.supplier_id" class="col-span-2"><PartyPicker v-model="subForm.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field :label="$t('projshow.scope_of_work')" :error="subForm.errors.scope" class="col-span-2"><input v-model="subForm.scope" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.contract_value')"><input v-model.number="subForm.contract_value" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.retention_3')"><input v-model.number="subForm.retention_percent" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="subForm.post(route('projects.subcontracts.store', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.save') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'subAdvance'" :title="$t('projshow.sub_advance_title', { n: selectedSub?.number })" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.date')"><DatePicker v-model="subAdvanceForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.amount')"><input v-model.number="subAdvanceForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.account')" class="col-span-2"><SelectCreate v-model="subAdvanceForm.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('projshow.default')" /></Field>
            </div>
            <template #footer><button @click="subAdvanceForm.post(route('projects.subcontracts.advance', selectedSub.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.issue') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'releaseSub'" :title="$t('projshow.sub_release_title', { n: selectedSub?.number })" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.date')"><DatePicker v-model="releaseForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.amount')" :error="releaseForm.errors.amount"><input v-model.number="releaseForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <p class="text-xs mt-2 text-slate-500">{{ $t('projshow.retention_held_2') }} <b class="font-mono">{{ money(Number(selectedSub?.retention_held || 0) - Number(selectedSub?.retention_released || 0)) }}</b>{{ $t('projshow.it_is_added_to_the_contractor_s') }}</p>
            <template #footer><button @click="releaseForm.post(route('projects.subcontracts.release-retention', selectedSub.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.release') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'progress'" :title="$t('projshow.update_progress_quantities_execu')" @close="close">
            <div class="max-h-96 overflow-y-auto space-y-1.5">
                <div v-for="l in progressForm.lines" :key="l.boq_item_id" class="flex items-center gap-3 text-xs" data-progress-row>
                    <span class="font-mono w-14">{{ l.code }}</span><span class="flex-1 truncate">{{ l.description }}</span>
                    <span class="text-slate-500 w-28 text-end">{{ $t('projshow.contract_billed', { contract: l.contract, billed: l.billed }) }}</span>
                    <input v-model="l.executed_quantity" type="text" inputmode="decimal" data-num class="w-24 rounded-lg border border-slate-200 px-2 py-1 font-mono text-center" />
                </div>
            </div>
            <p v-for="(msg, key) in progressForm.errors" :key="key" class="text-xs text-rose-600 mt-2">{{ msg }}</p>
            <template #footer><button @click="saveProgress" :disabled="progressForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.save') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'wip'" :title="$t('projshow.post_the_revenue_by_progress_adj')" @close="close">
            <Field :label="$t('projshow.period_end_date')" :error="wipForm.errors.date"><DatePicker v-model="wipForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <p class="text-xs mt-2 text-slate-500">{{ $t('projshow.wip_note', { wip: money(wip?.wip || 0) }) }}</p>
            <template #footer><button @click="wipForm.post(route('projects.wip.post', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.post') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'certify'" :title="$t('projshow.sub_certify_title', { n: selectedSub?.number })" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.date')"><DatePicker v-model="certifyForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.cumulative_value_of_works')" :error="certifyForm.errors.cumulative_value"><input v-model.number="certifyForm.cumulative_value" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.tax_rate')"><input v-model.number="certifyForm.vat_rate" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.contractor_invoice_number')"><input v-model="certifyForm.supplier_invoice_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <p class="text-xs mt-2">{{ $t('projshow.period_works_2') }} <b class="font-mono">{{ money(certifyForm.cumulative_value - (selectedSub?.certified_to_date || 0)) }}</b></p>
            <template #footer><button @click="certifyForm.post(route('projects.subcontracts.certify', selectedSub.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.approve') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'cost'" :title="$t('projshow.direct_cost_on_the_project')" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.type')"><select v-model="costForm.category" class="w-full rounded-xl border-slate-200 text-xs"><option value="labour">{{ $t('projshow.labour') }}</option><option value="equipment">{{ $t('projshow.equipment') }}</option><option value="other">{{ $t('projshow.other') }}</option></select></Field>
                <Field :label="$t('projshow.date')"><DatePicker v-model="costForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.statement')" :error="costForm.errors.description" class="col-span-2"><input v-model="costForm.description" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.amount_before_tax')" :error="costForm.errors.amount"><input v-model.number="costForm.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.input_tax')"><input v-model.number="costForm.vat_amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.payment')"><select v-model="costForm.paid_from" class="w-full rounded-xl border-slate-200 text-xs"><option value="accrued">{{ $t('projshow.payable_later') }}</option><option value="payables">{{ $t('projshow.on_credit_from_a_supplier') }}</option><option value="cash">{{ $t('projshow.cash') }}</option><option value="bank_transfer">{{ $t('projshow.bank_2') }}</option></select></Field>
                <Field v-if="costForm.paid_from === 'payables'" :label="$t('projshow.supplier')" :error="costForm.errors.supplier_id"><PartyPicker v-model="costForm.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field v-if="['cash', 'bank_transfer'].includes(costForm.paid_from)" :label="$t('projshow.account')"><SelectCreate v-model="costForm.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('projshow.default')" /></Field>
            </div>
            <template #footer><button @click="costForm.post(route('projects.costs.store', project.id), { ...opts, onSuccess: () => { close(); costForm.reset('description', 'amount', 'vat_amount'); } })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('projshow.record') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'materials'" :title="materialForm.direction === 'issue' ? $t('projshow.issue_materials_title') : $t('projshow.return_materials_title')" size="lg" @close="close">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field :label="$t('projshow.date')"><DatePicker v-model="materialForm.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.warehouse')"><SelectCreate v-model="materialForm.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('projshow.default')" /></Field>
                <Field :label="$t('projshow.reference')"><input v-model="materialForm.reference" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="relative mb-2">
                <input v-model="lookup" @input="searchProducts" :placeholder="$t('projshow.search_for_an_item')" class="w-full rounded-xl border-slate-200 text-xs" />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto"><button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="addMaterial(p)" @keydown.enter.prevent="addMaterial(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">{{ p.name_ar }}</button></div>
            </div>
            <table class="w-full text-xs"><tr v-for="(l, i) in materialForm.lines" :key="i" class="border-t border-slate-100"><td class="py-1.5">{{ l.name }}</td><td class="w-32"><input v-model.number="l.quantity" type="text" inputmode="decimal" data-num step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td><td class="w-8 text-center"><button @click="materialForm.lines.splice(i, 1)" class="text-rose-500">✕</button></td></tr></table>
            <p v-for="(msg, key) in materialForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <template #footer><button @click="materialForm.transform((d) => ({ ...d, lines: d.lines.map(({ product_id, quantity }) => ({ product_id, quantity })) })).post(route('projects.materials', project.id), { ...opts, onSuccess: () => { close(); materialForm.lines = []; } })" :disabled="!materialForm.lines.length" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('projshow.execute') }}</button></template>
        </Dialog>

        <Dialog :show="dialog === 'edit'" :title="$t('projshow.project_details')" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('projshow.name')" class="col-span-2"><input v-model="editForm.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.status')"><select v-model="editForm.status" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(label, key) in statusLabel" :key="key" :value="key">{{ label }}</option></select></Field>
                <Field :label="$t('projshow.location')"><input v-model="editForm.location" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.start')"><DatePicker v-model="editForm.start_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.expiry')"><DatePicker v-model="editForm.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('projshow.advance_recovery_2')"><input v-model.number="editForm.advance_percent" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('projshow.retention_3')"><input v-model.number="editForm.retention_percent" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="editForm.put(route('projects.update', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

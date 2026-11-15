<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import HijriDate from '@/Components/UI/HijriDate.vue';
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money, date } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ guarantees: Object, totals: Array, projects: Array, filters: Object });
const types = computed(() => ({ bid: t('prj_projects_guarantees.bid'), performance: t('prj_projects_guarantees.performance_final'), advance_payment: t('prj_projects_guarantees.advance_payment'), retention: t('prj_projects_guarantees.retention') }));
const statuses = computed(() => ({ active: t('prj_projects_guarantees.valid'), released: t('prj_projects_guarantees.released'), expired: t('prj_projects_guarantees.expired'), liquidated: t('prj_projects_guarantees.liquidated') }));
const open = ref(false);
const editing = ref(null);
const blank = { project_id: '', type: 'performance', number: '', bank_name: '', beneficiary: '', amount: 0, cash_margin: 0, issue_date: '', expiry_date: '', notes: '', status: 'active' };
const form = useForm({ ...blank });
const edit = (g) => { editing.value = g; Object.assign(form, { ...g, issue_date: g.issue_date?.substring(0, 10), expiry_date: g.expiry_date?.substring(0, 10), project_id: g.project_id || '' }); open.value = true; };
const create = () => { editing.value = null; Object.assign(form, blank); open.value = true; };
const save = () => editing.value
    ? form.put(route('projects.guarantees.update', editing.value.id), { onSuccess: () => (open.value = false) })
    : form.post(route('projects.guarantees.store'), { onSuccess: () => (open.value = false) });
const filter = (key, value) => router.get(route('projects.guarantees.index'), { ...props.filters, [key]: value || undefined }, { preserveState: true });
const daysLeft = (d) => Math.ceil((new Date(d) - new Date()) / 86400000);
</script>

<template>
    <AppLayout>
        <Head :title="$t('prj_projects_guarantees.bank_guarantees')" />
        <PageHeader :title="$t('prj_projects_guarantees.bank_guarantees')" :subtitle="$t('prj_projects_guarantees.bid_performance_advance_payment')" :crumbs="[{ label: $t('prj_projects_guarantees.contracting') }, { label: $t('prj_projects_guarantees.bank_guarantees') }]">
            <template #actions><button v-if="$can('projects.guarantees.create')" @click="create" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('prj_projects_guarantees.bank_guarantee') }}</button></template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div v-for="t in totals" :key="t.type" class="bg-white border border-slate-200 rounded-2xl p-3 text-xs"><div class="text-slate-400">{{ $t('prj_projects_guarantees.n_valid', { n0: types[t.type] }) }}</div><b class="font-mono text-sm">{{ money(t.amount) }}</b><div class="text-slate-400">{{ $t('prj_projects_guarantees.cash_margin_n', { n0: money(t.margin) }) }}</div></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="flex gap-2 p-3 border-b border-slate-100 text-xs">
                <select :value="filters.status || ''" @change="filter('status', $event.target.value)" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('prj_projects_guarantees.all_statuses') }}</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                <select :value="filters.type || ''" @change="filter('type', $event.target.value)" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('prj_projects_guarantees.all_types') }}</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('prj_projects_guarantees.number') }}</th><th class="px-3 py-2">{{ $t('prj_projects_guarantees.type') }}</th><th class="px-3 py-2">{{ $t('prj_projects_guarantees.project') }}</th><th class="px-3 py-2">{{ $t('prj_projects_guarantees.bank') }}</th><th class="px-3 py-2">{{ $t('prj_projects_guarantees.beneficiary') }}</th><th class="px-3 py-2 text-end">{{ $t('prj_projects_guarantees.amount') }}</th><th class="px-3 py-2">{{ $t('prj_projects_guarantees.expiry') }}</th><th class="px-3 py-2">{{ $t('prj_projects_guarantees.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="g in guarantees.data" :key="g.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ g.number }}</td><td class="px-3 py-2 text-center">{{ types[g.type] }}</td><td class="px-3 py-2 text-center font-mono">{{ g.project?.code || '—' }}</td>
                        <td class="px-3 py-2 text-center">{{ g.bank_name }}</td><td class="px-3 py-2 text-center">{{ g.beneficiary }}</td><td class="px-3 py-2 text-end font-mono">{{ money(g.amount) }}</td>
                        <td class="px-3 py-2 text-center" :class="g.status === 'active' && daysLeft(g.expiry_date) <= 30 ? 'text-rose-600 font-bold' : ''"><HijriDate :value="g.expiry_date" /></td>
                        <td class="px-3 py-2 text-center">{{ statuses[g.status] }}</td>
                        <td class="px-3 py-2 text-end"><button v-if="$can('projects.guarantees.edit')" @click="edit(g)" class="text-indigo-600 font-bold">{{ $t('prj_projects_guarantees.edit') }}</button></td>
                    </tr>
                    <tr v-if="!guarantees.data.length"><td colspan="9" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table></div>
            <Pagination :paginator="guarantees" />
        </div>
        <Dialog :show="open" :title="editing ? $t('prj_projects_guarantees.edit_the_bank_guarantee') : $t('prj_projects_guarantees.new_bank_guarantee')" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('prj_projects_guarantees.type')"><select v-model="form.type" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="$t('prj_projects_guarantees.project')"><select v-model="form.project_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} {{ p.name }}</option></select></Field>
                <Field :label="$t('prj_projects_guarantees.letter_number')" :error="form.errors.number"><input v-model="form.number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_guarantees.bank')" :error="form.errors.bank_name"><input v-model="form.bank_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_guarantees.beneficiary')" :error="form.errors.beneficiary" class="col-span-2"><input v-model="form.beneficiary" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_guarantees.amount')" :error="form.errors.amount"><input v-model.number="form.amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('prj_projects_guarantees.cash_margin')"><input v-model.number="form.cash_margin" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('prj_projects_guarantees.issue_date')" :error="form.errors.issue_date"><DatePicker v-model="form.issue_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('prj_projects_guarantees.expiry_date')" :error="form.errors.expiry_date"><DatePicker v-model="form.expiry_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="editing" :label="$t('prj_projects_guarantees.status')"><select v-model="form.status" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></Field>
            </div>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import { ask } from '@/composables/useDialog';
import { reactive, ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money, amount, monthLabel } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ budget: Object, months: Array, rows: Array, scope: { type: Object, default: null }, dimensionOptions: Object });
const dimension = ref(props.scope?.dimension || '');
const dimensionId = ref(props.scope?.id || '');
const openView = () => router.get(route('accounting.budgets.show', props.budget.id), dimension.value && dimensionId.value ? { dimension: dimension.value, dimension_id: dimensionId.value } : {});
const changeKind = () => { dimensionId.value = ''; if (!dimension.value) openView(); };

const mode = ref('plan'); // plan | compare
const editable = computed(() => props.budget.status !== 'approved');
const grid = reactive(Object.fromEntries(props.rows.map((r) => [r.id, { ...r.planned }])));

const rowTotal = (id) => props.months.reduce((s, p) => s + Number(grid[id][p] || 0), 0);
const actualTotal = (row) => props.months.reduce((s, p) => s + Number(row.actual[p] || 0), 0);
const spread = async (id) => {
    const answer = await ask(t('a_budget.spread_q'), { title: t('a_budget.spread_title'), type: 'number', default: rowTotal(id), required: true });
    if (answer === null) return;
    const total = Number(answer) || 0;
    const each = Math.round((total / props.months.length) * 100) / 100;
    props.months.forEach((p) => (grid[id][p] = each));
};

const form = useForm({ lines: [], approve: false, dimension: props.scope?.dimension || '', dimension_id: props.scope?.id || '' });
const save = (approve = false) => {
    form.lines = props.rows.flatMap((r) => props.months.map((p) => ({ account_id: r.id, period: p, amount: Number(grid[r.id][p] || 0) })).filter((l) => l.amount !== 0));
    form.approve = approve;
    form.put(route('accounting.budgets.update', props.budget.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="budget.name" />
        <PageHeader :title="budget.name" :subtitle="`${$t('a_period_end.fiscal_year')} ${budget.fiscal_year.name}`" :crumbs="[{ label: 'الموازنات', href: route('accounting.budgets.index') }, { label: budget.name }]">
            <template #actions>
                <div class="flex rounded-xl border border-slate-200 overflow-hidden text-xs">
                    <button @click="mode = 'plan'" :class="mode === 'plan' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-2 font-bold">{{ $t('a_budget.planning') }}</button>
                    <button @click="mode = 'compare'" :class="mode === 'compare' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-2 font-bold">{{ $t('a_budget.planned_vs_actual') }}</button>
                </div>
                <template v-if="editable && $can('accounting.budgets.edit')">
                    <button @click="save(false)" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
                    <button v-if="$can('accounting.budgets.approve')" @click="save(true)" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('a_budget.save_and_approve') }}</button>
                </template>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap items-end gap-3 text-xs" data-budget-view>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('budgetdim.view') }}</span>
                <select v-model="dimension" @change="changeKind" class="rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('budgetdim.account_level') }}</option>
                    <option value="cost_center">{{ $t('budgetdim.cost_center') }}</option>
                    <option value="project">{{ $t('budgetdim.project') }}</option>
                    <option value="branch">{{ $t('budgetdim.branch') }}</option>
                </select>
            </label>
            <label v-if="dimension" class="min-w-[220px]"><span class="block font-bold text-slate-600 mb-1">{{ $t('budgetdim.' + dimension) }}</span>
                <SelectCreate v-model="dimensionId" :options="dimensionOptions[dimension]" :placeholder="$t('budgetdim.pick')" :allow-empty="false" @update:modelValue="openView" />
            </label>
            <span v-if="scope" class="text-amber-700 font-bold">{{ $t('budgetdim.hint') }}</span>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-[11px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="text-start px-3 py-2 sticky start-0 bg-slate-50 min-w-[200px]">{{ $t('ui.account') }}</th>
                        <th v-if="mode === 'plan'" v-for="p in months" :key="p" class="px-1 py-2 font-mono">{{ monthLabel(p) }}</th>
                        <template v-else><th class="px-3 py-2 text-end">{{ $t('a_budget.planned') }}</th><th class="px-3 py-2 text-end">{{ $t('a_budget.actual') }}</th><th class="px-3 py-2 text-end">{{ $t('a_budget.variance') }}</th><th class="px-3 py-2 text-end">{{ $t('a_budget.achievement_rate') }}</th></template>
                        <th v-if="mode === 'plan'" class="px-3 py-2 text-end">{{ $t('a_budget.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-t border-slate-100">
                        <td class="px-3 py-1.5 sticky start-0 bg-white">
                            <span class="font-mono text-slate-400">{{ row.code }}</span> {{ row.name }}
                            <button v-if="mode === 'plan' && editable" @click="spread(row.id)" class="ms-1 text-indigo-500 hover:underline">{{ $t('a_budget.spread') }}</button>
                        </td>
                        <template v-if="mode === 'plan'">
                            <td v-for="p in months" :key="p" class="px-0.5 py-1"><input v-model.number="grid[row.id][p]" :disabled="!editable" type="text" inputmode="decimal" data-num step="0.01" class="w-24 rounded-lg border-slate-200 text-[11px] font-mono py-1 px-1.5" /></td>
                            <td class="px-3 py-1.5 text-end font-mono font-bold">{{ money(rowTotal(row.id)) }}</td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-1.5 text-end font-mono">{{ money(rowTotal(row.id)) }}</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ money(actualTotal(row)) }}</td>
                            <td class="px-3 py-1.5 text-end font-mono" :class="(row.type === 'expense' ? actualTotal(row) - rowTotal(row.id) : rowTotal(row.id) - actualTotal(row)) > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ amount(actualTotal(row) - rowTotal(row.id)) }}</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ rowTotal(row.id) ? Math.round(actualTotal(row) / rowTotal(row.id) * 100) + '%' : '—' }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

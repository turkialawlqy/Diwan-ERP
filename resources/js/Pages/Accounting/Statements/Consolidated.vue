<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import ExportButtons from '@/Components/UI/ExportButtons.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { money, amount } from '@/utils/format';

/** «كشف حساب مجمع»: several accounts (a parent account stands for everything below it) in one statement. */
const props = defineProps({
    accounts: Array,
    costCenters: Array,
    statement: Object,
    filters: Object,
});

const form = reactive({
    account_ids: [...(props.filters.account_ids || [])],
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    cost_center_id: props.filters.cost_center_id ?? '',
});

const picker = ref('');
const byId = computed(() => Object.fromEntries(props.accounts.map((a) => [a.id, a])));
const label = (a) => `${a.code} - ${a.name_ar}${a.is_parent ? ' ▸' : ''}`;
const chosen = computed(() => form.account_ids.map((id) => byId.value[id]).filter(Boolean));
const available = computed(() => props.accounts.filter((a) => !form.account_ids.includes(a.id)));

const add = (id) => {
    if (id && !form.account_ids.includes(Number(id))) form.account_ids.push(Number(id));
    picker.value = '';
};
const remove = (id) => { form.account_ids = form.account_ids.filter((x) => x !== id); };

const apply = () => {
    const query = { account_ids: form.account_ids, start_date: form.start_date, end_date: form.end_date };
    if (form.cost_center_id) query.cost_center_id = form.cost_center_id;
    router.get(route('accounting.statements.consolidated'), query, { preserveState: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_consol.consolidated_statement')" />
        <PageHeader :title="$t('a_consol.consolidated_statement')" :subtitle="$t('a_consol.subtitle')" :crumbs="[{ label: 'المحاسبة' }, { label: 'كشف حساب مجمع' }]">
            <template #actions>
                <ExportButtons v-if="statement" report="consolidated-statement" :params="{ account_ids: filters.account_ids, start_date: filters.start_date, end_date: filters.end_date, cost_center_id: filters.cost_center_id }" permission="accounting.ledger.export" />
            </template>
        </PageHeader>

        <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-6 gap-3 text-xs mb-4">
            <div class="md:col-span-6">
                <span class="block font-bold text-slate-600 mb-1">{{ $t('a_consol.accounts') }}</span>
                <div class="flex flex-wrap items-center gap-2" data-consolidated-chosen>
                    <span v-for="a in chosen" :key="a.id" class="inline-flex items-center gap-1.5 ps-3 pe-1.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 font-bold">
                        {{ label(a) }}
                        <button type="button" class="w-5 h-5 rounded-full hover:bg-indigo-100 inline-flex items-center justify-center" :aria-label="$t('a_consol.remove')" @click="remove(a.id)"><XMarkIcon class="w-3.5 h-3.5" /></button>
                    </span>
                    <div class="w-72"><SelectCreate :model-value="picker" :options="available" :label="label" :placeholder="$t('a_consol.add_account')" @update:model-value="add" data-consolidated-picker /></div>
                </div>
                <p class="text-slate-400 mt-1">{{ $t('a_consol.parent_hint') }}</p>
            </div>
            <label class="md:col-span-2" v-if="costCenters?.length"><span class="block font-bold text-slate-600 mb-1">{{ $t('a_ledger.cost_center') }}</span>
                <SelectCreate v-model="form.cost_center_id" :options="costCenters" :label="(c) => `${c.code} - ${c.name_ar}`" :empty-label="$t('a_ledger.all_centers')" />
            </label>
            <label class="md:col-span-2"><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.from') }}</span><DatePicker v-model="form.start_date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <label class="md:col-span-2"><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.to') }}</span><DatePicker v-model="form.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <div class="md:col-span-6 flex justify-end"><button type="submit" :disabled="!form.account_ids.length" class="rounded-xl bg-indigo-600 text-white font-bold py-2 px-6 hover:bg-indigo-700 disabled:opacity-40" data-consolidated-apply>{{ $t('ui.apply') }}</button></div>
        </form>

        <p v-if="!statement" class="bg-white border border-slate-200 rounded-2xl p-8 text-center text-slate-400 text-xs">{{ $t('a_consol.pick_accounts') }}</p>

        <template v-else>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <StatCard :label="$t('a_ledger.opening_balance_2')" :value="amount(statement.opening_balance)" />
                <StatCard :label="$t('a_ledger.total_debit')" :value="money(statement.period_debit)" tone="indigo" />
                <StatCard :label="$t('a_ledger.total_credit')" :value="money(statement.period_credit)" tone="amber" />
                <StatCard :label="$t('a_ledger.closing_balance')" :value="amount(statement.closing_balance)" :tone="statement.closing_balance < 0 ? 'rose' : 'emerald'" />
            </div>
            <p class="text-3xs text-slate-400 mb-3">{{ $t('a_consol.balance_note') }}</p>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-4" data-consolidated-summary>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-start px-3 py-2">{{ $t('ui.account') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('a_ledger.opening_balance_2') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.debit') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.credit') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('a_ledger.closing_balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in statement.accounts" :key="a.id" class="border-t border-slate-100">
                                <td class="px-3 py-1.5 font-semibold">{{ a.code }} - {{ a.name_ar }}</td>
                                <td class="px-3 py-1.5 text-end font-mono">{{ amount(a.opening) }}</td>
                                <td class="px-3 py-1.5 text-end font-mono">{{ amount(a.debit) }}</td>
                                <td class="px-3 py-1.5 text-end font-mono">{{ amount(a.credit) }}</td>
                                <td class="px-3 py-1.5 text-end font-mono font-bold">{{ amount(a.closing) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs" data-consolidated-lines>
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-start px-3 py-2">{{ $t('ui.date') }}</th>
                                <th class="text-start px-3 py-2">{{ $t('ui.account') }}</th>
                                <th class="text-start px-3 py-2">{{ $t('a_ledger.entry_number') }}</th>
                                <th class="text-start px-3 py-2">{{ $t('ui.description') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.debit') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.credit') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-amber-50/50 text-slate-700 font-bold" v-if="statement.lines.current_page === 1">
                                <td class="px-3 py-2" colspan="6">{{ $t('a_ledger.opening_balance') }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ amount(statement.opening_balance) }}</td>
                            </tr>
                            <tr v-for="line in statement.lines.data" :key="line.id" class="border-t border-slate-100">
                                <td class="px-3 py-1.5 font-mono whitespace-nowrap">{{ line.date }}</td>
                                <td class="px-3 py-1.5 whitespace-nowrap">{{ line.account_code }} - {{ line.account_name }}</td>
                                <td class="px-3 py-1.5 font-mono">{{ line.entry_number }}</td>
                                <td class="px-3 py-1.5 text-slate-600">{{ line.description || line.entry_description }}<span v-if="line.reference" class="text-slate-400"> · {{ line.reference }}</span></td>
                                <td class="px-3 py-1.5 text-end font-mono">{{ line.debit ? amount(line.debit) : '' }}</td>
                                <td class="px-3 py-1.5 text-end font-mono">{{ line.credit ? amount(line.credit) : '' }}</td>
                                <td class="px-3 py-1.5 text-end font-mono font-bold">{{ amount(line.balance) }}</td>
                            </tr>
                            <tr v-if="!statement.lines.data.length"><td colspan="7" class="px-4 py-6 text-center text-slate-400">{{ $t('a_ledger.no_transactions_in_this_period') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :links="statement.lines.links" />
            </div>
        </template>
    </AppLayout>
</template>

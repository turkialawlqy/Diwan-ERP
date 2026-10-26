<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { money, amount } from '@/utils/format';

const props = defineProps({
    accounts: Array,
    selectedAccount: Object,
    ledger: Object,
    costCenters: Array,
    filters: Object,
});

const form = reactive({
    account_id: props.filters.account_id ?? '',
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    cost_center_id: props.filters.cost_center_id ?? '',
});

const apply = () => {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null));
    router.get(route('accounting.ledger.index'), query, { preserveState: true });
};
</script>

<template>
    <AppLayout>
        <Head title="دفتر الأستاذ العام" />
        <PageHeader title="دفتر الأستاذ / كشف حساب" :subtitle="selectedAccount ? `${selectedAccount.code} - ${selectedAccount.name_ar}` : ''" :crumbs="[{ label: 'المحاسبة' }, { label: 'دفتر الأستاذ' }]" />

        <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-5 gap-3 text-xs mb-4">
            <label class="md:col-span-2"><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.account') }}</span>
                <select v-model="form.account_id" class="w-full rounded-xl border-slate-200 text-xs">
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option>
                </select>
            </label>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.from') }}</span><input v-model="form.start_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.to') }}</span><input v-model="form.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <div class="flex items-end"><button type="submit" class="w-full rounded-xl bg-indigo-600 text-white font-bold py-2 hover:bg-indigo-700">{{ $t('ui.apply') }}</button></div>
        </form>

        <template v-if="ledger">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <StatCard label="الرصيد الافتتاحي" :value="amount(ledger.opening_balance)" />
                <StatCard label="إجمالي المدين" :value="money(ledger.period_debit)" tone="indigo" />
                <StatCard label="إجمالي الدائن" :value="money(ledger.period_credit)" tone="amber" />
                <StatCard label="الرصيد الختامي" :value="amount(ledger.closing_balance)" :tone="ledger.closing_balance < 0 ? 'rose' : 'emerald'" />
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-start px-3 py-2">{{ $t('ui.date') }}</th>
                                <th class="text-start px-3 py-2">رقم القيد</th>
                                <th class="text-start px-3 py-2">{{ $t('ui.description') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.debit') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.credit') }}</th>
                                <th class="text-end px-3 py-2">{{ $t('ui.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-amber-50/50 text-slate-700 font-bold" v-if="ledger.lines.current_page === 1">
                                <td class="px-3 py-2" colspan="5">رصيد أول المدة</td>
                                <td class="px-3 py-2 text-end font-mono">{{ amount(ledger.opening_balance) }}</td>
                            </tr>
                            <tr v-for="line in ledger.lines.data" :key="line.id" class="border-t border-slate-100 hover:bg-slate-50/60">
                                <td class="px-3 py-2 font-mono text-slate-500">{{ line.date }}</td>
                                <td class="px-3 py-2"><Link :href="route('accounting.journals.show', line.journal_entry_id)" class="font-mono text-indigo-600 hover:underline">{{ line.entry_number }}</Link></td>
                                <td class="px-3 py-2 text-slate-700">{{ line.description || line.entry_description }}<span v-if="line.reference" class="text-slate-400"> · {{ line.reference }}</span></td>
                                <td class="px-3 py-2 text-end font-mono">{{ line.debit ? money(line.debit) : '' }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ line.credit ? money(line.credit) : '' }}</td>
                                <td class="px-3 py-2 text-end font-mono font-bold">{{ amount(line.balance) }}</td>
                            </tr>
                            <tr v-if="!ledger.lines.data.length"><td colspan="6" class="py-10 text-center text-slate-400">لا توجد حركات في هذه الفترة</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="ledger.lines" />
            </div>
        </template>
    </AppLayout>
</template>

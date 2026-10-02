<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';
import { PrinterIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    rows: Array,
    totals: Object,
    costCenters: Array,
    filters: Object,
});

const form = reactive({
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    level: props.filters.level ?? '',
    show_zero: !!props.filters.show_zero,
    cost_center_id: props.filters.cost_center_id ?? '',
});

const apply = () => {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== false && v !== null));
    router.get(route('accounting.trial-balance.index'), query, { preserveState: true });
};

const printPage = () => window.print();

const isBalanced = () => Math.abs(props.totals.closing_debit - props.totals.closing_credit) < 0.01;
</script>

<template>
    <AppLayout>
        <Head title="ميزان المراجعة" />
        <PageHeader title="ميزان المراجعة" :crumbs="[{ label: 'المحاسبة' }, { label: 'ميزان المراجعة' }]">
            <template #actions>
                <button @click="printPage" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:text-indigo-600">
                    <PrinterIcon class="w-4 h-4" /> {{ $t('ui.print') }}
                </button>
            </template>
        </PageHeader>

        <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-6 gap-3 text-xs mb-4 print:hidden">
            <label class="col-span-1"><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.from') }}</span><input v-model="form.start_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <label class="col-span-1"><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.to') }}</span><input v-model="form.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></label>
            <label><span class="block font-bold text-slate-600 mb-1">المستوى</span>
                <select v-model="form.level" class="w-full rounded-xl border-slate-200 text-xs">
                    <option value="">كل المستويات</option>
                    <option v-for="n in 5" :key="n" :value="n">حتى المستوى {{ n }}</option>
                </select>
            </label>
            <label><span class="block font-bold text-slate-600 mb-1">مركز التكلفة</span>
                <select v-model="form.cost_center_id" class="w-full rounded-xl border-slate-200 text-xs">
                    <option value="">الكل</option>
                    <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name_ar }}</option>
                </select>
            </label>
            <label class="flex items-end gap-2 pb-2"><input v-model="form.show_zero" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> <span>إظهار الأرصدة الصفرية</span></label>
            <div class="flex items-end"><button type="submit" class="w-full rounded-xl bg-indigo-600 text-white font-bold py-2 hover:bg-indigo-700">{{ $t('ui.apply') }}</button></div>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="hidden print:block text-center py-3">
                <h2 class="font-black">ميزان المراجعة</h2>
                <p class="text-xs">من {{ filters.start_date }} إلى {{ filters.end_date }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th rowspan="2" class="text-start px-3 py-2">الحساب</th>
                            <th colspan="2" class="px-3 py-2 border-s border-slate-200">الرصيد الافتتاحي</th>
                            <th colspan="2" class="px-3 py-2 border-s border-slate-200">حركة الفترة</th>
                            <th colspan="2" class="px-3 py-2 border-s border-slate-200">الرصيد الختامي</th>
                        </tr>
                        <tr>
                            <th class="px-3 py-1 border-s border-slate-200">{{ $t('ui.debit') }}</th><th class="px-3 py-1">{{ $t('ui.credit') }}</th>
                            <th class="px-3 py-1 border-s border-slate-200">{{ $t('ui.debit') }}</th><th class="px-3 py-1">{{ $t('ui.credit') }}</th>
                            <th class="px-3 py-1 border-s border-slate-200">{{ $t('ui.debit') }}</th><th class="px-3 py-1">{{ $t('ui.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" :class="[row.is_parent ? 'bg-slate-50/70 font-bold text-slate-800' : 'text-slate-700', 'border-t border-slate-100']">
                            <td class="px-3 py-1.5" :style="{ paddingInlineStart: (row.level * 14) + 'px' }">
                                <Link v-if="!row.is_parent" :href="route('accounting.ledger.index', { account_id: row.id, start_date: filters.start_date, end_date: filters.end_date })" class="hover:text-indigo-600">
                                    <span class="font-mono text-slate-400">{{ row.code }}</span> {{ row.name }}
                                </Link>
                                <span v-else><span class="font-mono text-slate-400">{{ row.code }}</span> {{ row.name }}</span>
                            </td>
                            <td class="px-3 py-1.5 text-end font-mono border-s border-slate-100">{{ row.opening_debit ? money(row.opening_debit) : '' }}</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ row.opening_credit ? money(row.opening_credit) : '' }}</td>
                            <td class="px-3 py-1.5 text-end font-mono border-s border-slate-100">{{ row.debit ? money(row.debit) : '' }}</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ row.credit ? money(row.credit) : '' }}</td>
                            <td class="px-3 py-1.5 text-end font-mono border-s border-slate-100">{{ row.closing_debit ? money(row.closing_debit) : '' }}</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ row.closing_credit ? money(row.closing_credit) : '' }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                    <tfoot class="bg-slate-100 font-black text-slate-900">
                        <tr>
                            <td class="px-3 py-2">{{ $t('ui.total') }}
                                <span :class="isBalanced() ? 'text-emerald-600' : 'text-rose-600'" class="ms-2 text-[11px]">{{ isBalanced() ? '✓ متوازن' : '✗ غير متوازن' }}</span>
                            </td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(totals.opening_debit) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(totals.opening_credit) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(totals.debit) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(totals.credit) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(totals.closing_debit) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(totals.closing_credit) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

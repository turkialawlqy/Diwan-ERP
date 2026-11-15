<script setup>
import { useI18n } from 'vue-i18n';
import RowActions from '@/Components/UI/RowActions.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    EyeIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    entries: Object,
    stats: Object,
    filters: Object,
    creators: { type: Array, default: () => [] },
    sourceTypes: { type: Array, default: () => [] },
});

const statusBadge = computed(() => ({
    draft: [t('a_common.draft'), 'bg-amber-50 text-amber-700 border-amber-200'],
    posted: [t('a_common.posted'), 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    reversed: [t('a_common.reversed'), 'bg-rose-50 text-rose-700 border-rose-200'],
}));
const typeLabels = computed(() => ({ manual: t('a_journals.ty_manual'), system: t('a_journals.ty_system'), opening: t('a_journals.ty_opening'), closing: t('a_journals.ty_closing'), adjustment: t('a_journals.ty_adjustment'), recurring: t('a_journals.ty_recurring'), reversal: t('a_journals.ty_reversal') }));

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const f = reactive({
    status: props.filters?.status || '',
    entry_type: props.filters?.entry_type || '',
    from: props.filters?.from || '',
    to: props.filters?.to || '',
    created_by: props.filters?.created_by || '',
    source: props.filters?.source || '',
});
const filtered = () => !!(search.value || Object.values(f).some(Boolean));

const handleSearch = () => {
    const query = Object.fromEntries(Object.entries({ search: search.value, ...f }).filter(([, v]) => v !== '' && v !== null));
    router.get(route('accounting.journals.index'), query, { preserveState: true, replace: true });
};
const resetFilters = () => {
    search.value = '';
    Object.keys(f).forEach((k) => { f[k] = ''; });
    handleSearch();
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

</script>

<template>
    <AppLayout own-errors>
        <Head :title="$t('a_journals.journal_entries')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader year-scoped :title="$t('a_journals.journal_entries')" :crumbs="[{ label: 'المحاسبة' }, { label: 'القيود اليومية' }]">
<template #actions>
<button 
                        @click="handleSearch"
                        :title="$t('a_journals.refresh_data')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<button 
                        @click.stop="isFilterOpen = !isFilterOpen"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-medium text-xs sm:text-sm transition-all duration-150 shadow-xs border',
                            isFilterOpen || filtered()
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700' 
                                : 'bg-slate-100/90 border-slate-200 text-slate-700 hover:bg-slate-200/80'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('a_journals.search_and_filter') }}</span>
                    </button>

                    <Link v-if="$can('accounting.journals.create')"
                        :href="route('accounting.journals.create')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('a_journals.create_a_journal_entry') }}</span>
                    </Link>
</template>
</PageHeader>

            <!-- Collapsible Search & Filter Panel -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div v-if="isFilterOpen" class="bg-white rounded-2xl border border-indigo-100/80 p-5 shadow-sm space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('a_journals.search_by_entry_number_descripti') }}</label>
                            <input 
                                v-model="search"
                                @keyup.enter="handleSearch"
                                type="text" 
                                :placeholder="$t('a_journals.type_to_search')"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                        <div class="flex items-end gap-2">
                            <button 
                                @click="handleSearch"
                                class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-colors shadow-xs"
                                data-journal-filter-apply
                            >
                                {{ $t('a_journals.apply_filter') }}
                            </button>
                            <button 
                                @click="resetFilters"
                                class="px-4 py-2.5 text-xs font-medium text-slate-600 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors"
                            >
                                {{ $t('a_journals.reset') }}
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 text-xs" data-journal-filters>
                        <label><span class="block font-semibold text-slate-600 mb-1">{{ $t('journals.status') }}</span>
                            <select v-model="f.status" class="w-full rounded-xl border-slate-200 text-xs ps-3 pe-8"><option value="">{{ $t('journals.all') }}</option><option v-for="(v, k) in statusBadge" :key="k" :value="k">{{ v[0] }}</option></select></label>
                        <label><span class="block font-semibold text-slate-600 mb-1">{{ $t('journals.type') }}</span>
                            <select v-model="f.entry_type" class="w-full rounded-xl border-slate-200 text-xs ps-3 pe-8"><option value="">{{ $t('journals.all') }}</option><option v-for="(v, k) in typeLabels" :key="k" :value="k">{{ v }}</option></select></label>
                        <label><span class="block font-semibold text-slate-600 mb-1">{{ $t('journals.creator') }}</span>
                            <select v-model="f.created_by" class="w-full rounded-xl border-slate-200 text-xs ps-3 pe-8"><option value="">{{ $t('journals.all') }}</option><option v-for="u in creators" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                        <label><span class="block font-semibold text-slate-600 mb-1">{{ $t('journals.source') }}</span>
                            <select v-model="f.source" class="w-full rounded-xl border-slate-200 text-xs ps-3 pe-8"><option value="">{{ $t('journals.all') }}</option><option value="none">{{ $t('journals.manual_no_doc') }}</option><option v-for="t in sourceTypes" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
                        <label><span class="block font-semibold text-slate-600 mb-1">{{ $t('journals.from') }}</span><DatePicker v-model="f.from" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                        <label><span class="block font-semibold text-slate-600 mb-1">{{ $t('journals.to') }}</span><DatePicker v-model="f.to" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                    </div>
                </div>
            </transition>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('a_journals.total_journal_entries') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_entries || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('a_journals.volume_of_recorded_transactions') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ formatCurrency(stats?.total_volume) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('a_journals.posted_and_approved_entries') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ stats?.posted_count || 0 }}</p>
                </div>
            </div>

            <div v-if="stats?.by_type && Object.keys(stats.by_type).length" class="flex flex-wrap gap-2 text-xs" data-journal-types>
                <span v-for="(n, k) in stats.by_type" :key="k" class="px-3 py-1 rounded-full bg-white border border-slate-200 text-slate-600">{{ typeLabels[k] || k }} <b class="font-mono text-slate-800">{{ n }}</b></span>
            </div>

            <!-- Journal Entries Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">{{ $t('a_journals.entry_number') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('a_journals.date') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('a_journals.general_entry_description') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('a_journals.reference') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('journals.source') }}</th>
                                <th class="py-4 px-6 text-end">{{ $t('a_journals.total_debit') }}</th>
                                <th class="py-4 px-6 text-end">{{ $t('a_journals.total_credit') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('a_journals.status') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('a_journals.view_voucher') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    {{ entry.entry_number }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ entry.date ? entry.date.substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6 font-medium text-slate-900 max-w-sm truncate">
                                    {{ entry.description }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-500">
                                    {{ entry.reference || '-' }}
                                </td>
                                <td class="py-4 px-6 text-xs">
                                    <a v-if="entry.source?.url" :href="entry.source.url" class="text-emerald-700 hover:underline" data-source-link>{{ entry.source.label }}</a>
                                    <span v-else class="text-slate-500">{{ entry.source?.label || $t('journals.manual') }}</span>
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(entry.total_debit) }} ﷼
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(entry.total_credit) }} ﷼
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span :class="[statusBadge[entry.status]?.[1], 'px-2.5 py-0.5 rounded-full text-2xs font-bold border']">
                                        {{ statusBadge[entry.status]?.[0] || entry.status }}
                                    </span>
                                    <span class="block text-[10px] text-slate-400 mt-0.5">{{ typeLabels[entry.entry_type] || '' }}</span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <Link 
                                        :href="route('accounting.journals.show', entry.id)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold text-indigo-600 hover:bg-indigo-50 rounded-lg transition"
                                    >
                                        <EyeIcon class="w-3.5 h-3.5" />
                                        <span>{{ $t('a_journals.view_voucher') }}</span>
                                    </Link>
                                    <RowActions :remove="entry.status === 'draft' && $can('accounting.journals.delete') ? route('accounting.journals.destroy', entry.id) : null" :confirm="$t('a_journals.confirm_delete', { number: entry.entry_number })" />
                                </td>
                            </tr>
                            <tr v-if="!entries.data || entries.data.length === 0">
                                <td colspan="9" class="py-12 text-center text-slate-400">
                                    {{ $t('a_journals.no_journal_entries_recorded') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="entries?.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>{{ $t('s_invoices.total_n', { n: entries.total || 0 }) }}</span>
                        <span>{{ $t('a_journals.per_page') }}</span>
                        <select 
                            :value="entries.per_page"
                            @change="router.get(route('accounting.journals.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>{{ $t('s_invoices.page_of', { a: entries.current_page, b: entries.last_page || 1 }) }}</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="entries.prev_page_url" 
                                :href="entries.prev_page_url"
                                class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors"
                            >
                                <ChevronRightIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </Link>
                            <button 
                                v-else 
                                disabled 
                                class="p-1.5 rounded-lg border border-slate-100 text-slate-300 cursor-not-allowed"
                            >
                                <ChevronRightIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </button>
                            <span class="w-7 h-7 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 font-bold flex items-center justify-center">
                                {{ entries.current_page }}
                            </span>
                            <Link 
                                v-if="entries.next_page_url" 
                                :href="entries.next_page_url"
                                class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors"
                            >
                                <ChevronLeftIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </Link>
                            <button 
                                v-else 
                                disabled 
                                class="p-1.5 rounded-lg border border-slate-100 text-slate-300 cursor-not-allowed"
                            >
                                <ChevronLeftIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

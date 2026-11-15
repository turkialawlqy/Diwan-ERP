<script setup>
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ImportButton from '@/Components/UI/ImportButton.vue';
import { notify } from '@/composables/useDialog';
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    BookOpenIcon,
    XMarkIcon,
    FolderIcon,
    FolderOpenIcon,
    DocumentIcon,
    DocumentTextIcon,
    PencilSquareIcon,
    TrashIcon,
    ArrowsPointingOutIcon,
    ArrowsPointingInIcon,
    PlusCircleIcon,
    ArrowTopRightOnSquareIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    accounts: Array,
    parentAccounts: Array,
    stats: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const selectedType = ref(props.filters?.type || '');

// Tree Expansion State
// Default: expand all parent accounts initially
const expandedNodes = ref(new Set(
    props.accounts.filter(a => a.is_parent || (a.children_count && a.children_count > 0)).map(a => a.id)
));

// Fast Parent Lookup Map
const parentMap = computed(() => {
    const map = {};
    (props.accounts || []).forEach(acc => {
        map[acc.id] = acc;
    });
    return map;
});

// Toggle a single parent node
const toggleNode = (id) => {
    if (expandedNodes.value.has(id)) {
        expandedNodes.value.delete(id);
    } else {
        expandedNodes.value.add(id);
    }
};

// Expand All
const expandAll = () => {
    (props.accounts || []).forEach(acc => {
        if (acc.is_parent || acc.children_count > 0) {
            expandedNodes.value.add(acc.id);
        }
    });
};

// Collapse All
const collapseAll = () => {
    expandedNodes.value.clear();
};

// Check if a row should be visible based on expanded ancestor parents
const isRowVisible = (acc) => {
    // If user is searching or filtering, display all matching rows
    if (search.value.trim() || selectedType.value) {
        return true;
    }
    if (!acc.parent_id) {
        return true;
    }
    let currentParentId = acc.parent_id;
    while (currentParentId) {
        if (!expandedNodes.value.has(currentParentId)) {
            return false;
        }
        const p = parentMap.value[currentParentId];
        currentParentId = p ? p.parent_id : null;
    }
    return true;
};

// Calculate Account Hierarchy Level
const getAccountLevel = (acc) => {
    let level = 1;
    let currentParentId = acc.parent_id;
    while (currentParentId) {
        level++;
        const p = parentMap.value[currentParentId];
        currentParentId = p ? p.parent_id : null;
    }
    return level;
};

// Calculate Effective Balance (sum of child leaves for parent accounts, or direct balance for leaf accounts)
const getAccountEffectiveBalance = (acc) => {
    if (!acc.is_parent && (!acc.children_count || acc.children_count === 0)) {
        return Number(acc.balance || 0);
    }
    let sum = 0;
    const findDescendants = (parentId) => {
        (props.accounts || []).forEach(child => {
            if (child.parent_id === parentId) {
                if (!child.is_parent && (!child.children_count || child.children_count === 0)) {
                    sum += Number(child.balance || 0);
                } else {
                    findDescendants(child.id);
                }
            }
        });
    };
    findDescendants(acc.id);
    return sum;
};

// Suggest next available child code based on parent
const suggestChildCode = (parentId) => {
    if (!parentId) return '';
    const parent = parentMap.value[parentId];
    if (!parent) return '';
    
    // Find existing direct children
    const siblings = (props.accounts || []).filter(a => a.parent_id === parentId);
    if (siblings.length === 0) {
        return `${parent.code}01`;
    }
    
    // Try to find the highest sibling code
    const codes = siblings.map(s => {
        const num = parseInt(s.code, 10);
        return isNaN(num) ? 0 : num;
    }).sort((a, b) => b - a);
    
    if (codes.length > 0 && codes[0] > 0) {
        return String(codes[0] + 1);
    }
    return `${parent.code}${siblings.length + 1}`;
};

// Delete Handler
const deleteAccount = (acc) => {
    if (acc.children_count > 0) {
        notify(t('a_accounts.has_children'));
        return;
    }
    if (acc.journal_lines_count > 0) {
        notify(t('a_accounts.has_entries'));
        return;
    }

    if (confirm(t('a_accounts.confirm_delete', { name: `${acc.code} - ${acc.name_ar}` }))) {
        router.delete(route('accounting.accounts.destroy', acc.id), {
            preserveScroll: true,
        });
    }
};

const handleSearch = () => {
    router.get(route('accounting.accounts.index'), {
        search: search.value || undefined,
        type: selectedType.value || undefined,
    }, { preserveState: true, replace: true });
};

const setTypeFilter = (type) => {
    selectedType.value = type;
    handleSearch();
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const typeLabels = computed(() => ({ asset: t('a_common.assets'), liability: t('a_common.liabilities'), equity: t('a_common.equity'), revenue: t('a_common.revenue'), expense: t('a_common.expenses') }));

const typeBadgeColors = {
    asset: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    liability: 'bg-amber-50 text-amber-700 border-amber-200',
    equity: 'bg-purple-50 text-purple-700 border-purple-200',
    revenue: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    expense: 'bg-rose-50 text-rose-700 border-rose-200',
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_accounts.chart_of_accounts')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('a_accounts.chart_of_accounts')" :crumbs="[{ label: 'المحاسبة' }, { label: 'دليل الحسابات' }]">
<template #actions>

                    <button 
                        @click="handleSearch"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        :title="$t('a_accounts.refresh_data')"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<button 
                        @click="expandAll"
                        class="px-3.5 py-2.5 bg-white border border-slate-200/80 text-slate-700 hover:text-indigo-600 hover:bg-slate-50 rounded-xl text-xs font-semibold shadow-xs transition flex items-center gap-1.5"
                        :title="$t('a_accounts.expand_all_branches_of_the_chart')"
                    >
                        <ArrowsPointingOutIcon class="w-4 h-4" />
                        <span>{{ $t('a_accounts.expand_all') }}</span>
                    </button>

                    <button 
                        @click="collapseAll"
                        class="px-3.5 py-2.5 bg-white border border-slate-200/80 text-slate-700 hover:text-indigo-600 hover:bg-slate-50 rounded-xl text-xs font-semibold shadow-xs transition flex items-center gap-1.5"
                        :title="$t('a_accounts.collapse_all_branches_of_the_cha')"
                    >
                        <ArrowsPointingInIcon class="w-4 h-4" />
                        <span>{{ $t('a_accounts.collapse_all') }}</span>
                    </button>

                    <Link v-if="$can('accounting.accounts.create')" :href="route('accounting.accounts.create')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('a_accounts.add_a_new_top_level_account') }}</span>
                    </Link>
<ImportButton v-if="$can('accounting.accounts.create')" type="accounts" :title="$t('a_accounts.import_the_chart_of_accounts_fro')" :hint="$t('a_accounts.a_new_account_is_created_under_i')" />
</template>
</PageHeader>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-indigo-300 transition" @click="setTypeFilter('asset')">
                    <span class="text-xs font-bold text-slate-500">{{ $t('a_accounts.assets') }}</span>
                    <h3 class="text-lg font-black font-mono text-indigo-600 truncate">
                        {{ formatCurrency(stats?.total_assets) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-amber-300 transition" @click="setTypeFilter('liability')">
                    <span class="text-xs font-bold text-slate-500">{{ $t('a_accounts.liabilities') }}</span>
                    <h3 class="text-lg font-black font-mono text-amber-600 truncate">
                        {{ formatCurrency(stats?.total_liabilities) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-purple-300 transition" @click="setTypeFilter('equity')">
                    <span class="text-xs font-bold text-slate-500">{{ $t('a_accounts.equity') }}</span>
                    <h3 class="text-lg font-black font-mono text-purple-600 truncate">
                        {{ formatCurrency(stats?.total_equity) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-emerald-300 transition" @click="setTypeFilter('revenue')">
                    <span class="text-xs font-bold text-slate-500">{{ $t('a_accounts.revenues') }}</span>
                    <h3 class="text-lg font-black font-mono text-emerald-600 truncate">
                        {{ formatCurrency(stats?.total_revenues) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs space-y-1 cursor-pointer hover:border-rose-300 transition" @click="setTypeFilter('expense')">
                    <span class="text-xs font-bold text-slate-500">{{ $t('a_accounts.expenses') }}</span>
                    <h3 class="text-lg font-black font-mono text-rose-600 truncate">
                        {{ formatCurrency(stats?.total_expenses) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                    </h3>
                </div>
            </div>

            <!-- Search and Filter Tabs -->
            <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="relative w-full sm:w-80">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                    <input 
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text" 
                        :placeholder="$t('a_accounts.search_by_code_or_account_name')"
                        class="w-full ps-9 pe-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                    />
                </div>

                <div class="flex items-center gap-1.5 overflow-x-auto w-full sm:w-auto pb-1 sm:pb-0">
                    <button 
                        @click="setTypeFilter('')"
                        :class="[!selectedType ? 'bg-indigo-600 text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100', 'px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition']"
                    >
                        {{ $t('s_clients.all') }} ({{ stats?.total_accounts_count }})
                    </button>
                    <button 
                        v-for="(label, key) in typeLabels" 
                        :key="key"
                        @click="setTypeFilter(key)"
                        :class="[selectedType === key ? 'bg-indigo-600 text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100', 'px-3 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition']"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>

            <!-- Accounts Tree Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start w-32">{{ $t('a_accounts.account_code') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('a_accounts.chart_of_accounts_tree') }}</th>
                                <th class="py-4 px-6 text-start w-28">{{ $t('a_accounts.type') }}</th>
                                <th class="py-4 px-6 text-start w-28">{{ $t('a_accounts.account_nature') }}</th>
                                <th class="py-4 px-6 text-start w-24">{{ $t('a_accounts.level') }}</th>
                                <th class="py-4 px-6 text-end w-36">{{ $t('a_accounts.running_balance') }}</th>
                                <th class="py-4 px-6 text-center w-56">{{ $t('a_accounts.account_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="acc in accounts" :key="acc.id">
                                <tr 
                                    v-if="isRowVisible(acc)"
                                    :class="[
                                        acc.is_parent || acc.children_count > 0 
                                            ? 'bg-slate-50/60 font-bold text-slate-900 hover:bg-slate-100/60' 
                                            : 'hover:bg-slate-50/80 text-slate-700',
                                        'transition-colors group'
                                    ]"
                                >
                                    <!-- Account Code -->
                                    <td class="py-3 px-4 font-mono font-bold text-indigo-600 whitespace-nowrap">
                                        {{ acc.code }}
                                    </td>

                                    <!-- Tree Name with Expand/Collapse and Hierarchy Indentation -->
                                    <td class="py-3 px-4">
                                        <div 
                                            class="flex items-center gap-2"
                                            :style="{ paddingInlineStart: ((getAccountLevel(acc) - 1) * 24) + 'px' }"
                                        >
                                            <!-- Collapse / Expand Toggle Button for Parents -->
                                            <button 
                                                v-if="acc.is_parent || acc.children_count > 0"
                                                @click.stop="toggleNode(acc.id)"
                                                class="w-5 h-5 flex items-center justify-center rounded-md hover:bg-slate-200/80 text-slate-500 transition-colors flex-shrink-0"
                                                :title="expandedNodes.has(acc.id) ? $t('a_accounts.collapse_branch') : $t('a_accounts.expand_branch')"
                                            >
                                                <ChevronDownIcon 
                                                    :class="[
                                                        expandedNodes.has(acc.id) ? 'rotate-0' : '-rotate-90',
                                                        'w-3.5 h-3.5 text-slate-600 transition-transform duration-200'
                                                    ]" 
                                                />
                                            </button>
                                            <span v-else class="w-5 flex-shrink-0"></span>

                                            <!-- Folder / File Icon -->
                                            <template v-if="acc.is_parent || acc.children_count > 0">
                                                <FolderOpenIcon 
                                                    v-if="expandedNodes.has(acc.id)" 
                                                    class="w-4 h-4 text-amber-500 flex-shrink-0" 
                                                />
                                                <FolderIcon 
                                                    v-else 
                                                    class="w-4 h-4 text-amber-500 flex-shrink-0" 
                                                />
                                            </template>
                                            <DocumentIcon v-else class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" />

                                            <!-- Account Name -->
                                            <span class="truncate" :title="acc.name_ar">
                                                {{ acc.name_ar }}
                                            </span>

                                            <!-- Sub-accounts count badge if parent -->
                                            <span 
                                                v-if="acc.children_count > 0" 
                                                class="text-3xs font-semibold px-1.5 py-0.2 rounded-full bg-slate-200/70 text-slate-600"
                                                :title="$t('a_accounts.sub_count', { n: acc.children_count })"
                                            >
                                                {{ acc.children_count }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Account Type Badge -->
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <span :class="[typeBadgeColors[acc.type], 'px-2 py-0.5 rounded-md text-3xs font-semibold border']">
                                            {{ typeLabels[acc.type] }}
                                        </span>
                                    </td>

                                    <!-- Nature (Debit/Credit) -->
                                    <td class="py-3 px-4 text-slate-500 text-3xs whitespace-nowrap">
                                        {{ acc.nature === 'debit' ? $t('a_common.debit') : $t('a_common.credit') }}
                                    </td>

                                    <!-- Hierarchy Level -->
                                    <td class="py-3 px-4 text-slate-400 text-3xs whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 font-mono">
                                            {{ $t('a_accounts.level_n', { n: getAccountLevel(acc) }) }}
                                        </span>
                                    </td>

                                    <!-- Running Balance -->
                                    <td class="py-3 px-4 font-mono font-black text-end whitespace-nowrap">
                                        <span :class="[
                                            getAccountEffectiveBalance(acc) < 0 ? 'text-rose-600' : (acc.is_parent || acc.children_count > 0 ? 'text-indigo-700' : 'text-slate-900')
                                        ]">
                                            {{ formatCurrency(getAccountEffectiveBalance(acc)) }} <span class="text-3xs font-normal">﷼</span>
                                        </span>
                                    </td>

                                    <!-- Action Buttons Column -->
                                    <td class="py-2.5 px-4 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            <!-- 1. زر إضافة حساب فرعي (Add Sub-Account) -->
                                            <Link v-if="$can('accounting.accounts.create')" :href="route('accounting.accounts.create', { parent_id: acc.id })" class="px-2 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-3xs font-bold transition flex items-center gap-1 border border-indigo-200/60 shadow-2xs" :title="$t('a_accounts.add_a_sub_account_under_this_acc')">
                                                <PlusCircleIcon class="w-3.5 h-3.5" />
                                                <span>{{ $t('a_accounts.sub_account') }}</span>
                                            </Link>

                                            <!-- 2. زر كشف الحساب للحسابات الفرعية (Statement of Account) -->
                                            <Link 
                                                v-if="!acc.is_parent || acc.children_count === 0"
                                                :href="route('accounting.ledger.index', { account_id: acc.id })"
                                                class="px-2 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg text-3xs font-bold transition flex items-center gap-1 border border-emerald-200/60 shadow-2xs"
                                                :title="$t('a_accounts.view_the_account_statement_and_d')"
                                            >
                                                <DocumentTextIcon class="w-3.5 h-3.5" />
                                                <span>{{ $t('a_accounts.statement') }}</span>
                                            </Link>

                                            <!-- 3. زر تعديل الحساب (Edit) -->
                                            <Link v-if="$can('accounting.accounts.edit')" :href="route('accounting.accounts.edit', acc.id)" class="p-1 text-slate-500 hover:text-indigo-600 hover:bg-slate-100 rounded-lg transition" :title="$t('a_accounts.edit_account_details')">
                                                <PencilSquareIcon class="w-4 h-4" />
                                            </Link>

                                            <!-- 4. زر حذف الحساب (Delete) -->
                                            <button 
                                                @click.stop="deleteAccount(acc)"
                                                :disabled="acc.children_count > 0 || acc.journal_lines_count > 0"
                                                :class="[
                                                    acc.children_count > 0 || acc.journal_lines_count > 0 
                                                        ? 'text-slate-300 cursor-not-allowed' 
                                                        : 'text-slate-400 hover:text-rose-600 hover:bg-rose-50',
                                                    'p-1 rounded-lg transition'
                                                ]"
                                                :title="
                                                    acc.children_count > 0 
                                                        ? $t('a_accounts.tip_children') 
                                                        : (acc.journal_lines_count > 0 ? $t('a_accounts.tip_entries') : $t('a_accounts.tip_delete'))
                                                "
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!accounts || accounts.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    {{ $t('a_accounts.no_accounts_match_the_search') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

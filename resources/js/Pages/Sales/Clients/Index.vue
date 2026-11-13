<script setup>
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ImportButton from '@/Components/UI/ImportButton.vue';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import axios from 'axios';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    EllipsisVerticalIcon,
    EyeIcon,
    PencilSquareIcon,
    TrashIcon,
    ChevronLeftIcon,
    UserPlusIcon,
    UsersIcon,
    CheckIcon,
    PhoneIcon,
    DocumentTextIcon,
    PrinterIcon,
    BuildingOfficeIcon,
    UserIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    customers: Object,
    filters: Object,
    stats: Object,
    nextCode: {
        type: String,
        default: '1-000001',
    },
});

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const customerType = ref(props.filters?.customer_type || '');
const activeMenu = ref(null);

const openAddModal = () => router.visit(route('sales.clients.create'));
const openEditModal = (client) => router.visit(route('sales.clients.edit', client.id));
const openViewModal = (client) => router.visit(route('sales.clients.show', client.id));

const toggleMenu = (id) => {
    activeMenu.value = activeMenu.value === id ? null : id;
};

const closeMenu = () => {
    activeMenu.value = null;
};

const applyFilters = () => {
    router.get(
        route('sales.clients.index'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
            customer_type: customerType.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilters = () => {
    search.value = '';
    status.value = '';
    customerType.value = '';
    router.get(route('sales.clients.index'), {}, { preserveState: true, replace: true });
};

const deleteCustomer = (id, name) => {
    if (confirm(t('s_clients.confirm_delete', { name }))) {
        router.delete(route('sales.clients.destroy', id), {
            onSuccess: () => closeMenu(),
        });
    }
};

const formatCurrency = (val) => {
    if (val === null || val === undefined || Number(val) === 0) return '-';
    return Number(val).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ﷼';
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_clients.customer_management')" />

        <div class="space-y-5" @click="closeMenu" dir="rtl">
            <!-- Top Breadcrumbs & Action Bar -->
            <PageHeader :title="$t('s_clients.customers_2')" :crumbs="[{ label: 'العملاء' }]">
<template #actions>

                    
                    <button 
                        @click="router.reload()" 
                        class="p-2 bg-white border border-slate-200/80 rounded-xl text-slate-400 hover:text-indigo-600 hover:border-indigo-100 transition-colors shadow-xs"
                        :title="$t('s_clients.refresh_data')"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<button
                        @click="isFilterOpen = !isFilterOpen"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2 rounded-xl border text-xs font-semibold transition-all shadow-xs',
                            isFilterOpen || search || status || customerType
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700' 
                                : 'bg-white border-slate-200/80 text-slate-600 hover:bg-slate-50'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4 text-slate-400" />
                        <span>{{ $t('s_clients.filter_and_search') }}</span>
                    </button>

                    <button
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('s_clients.add_customer') }}</span>
                    </button>
<ImportButton v-if="$can('sales.clients.create')" type="customers" :title="$t('s_clients.import_customers_from_excel')" :hint="$t('s_clients.the_opening_balance_column_is_op')" />
</template>
</PageHeader>

            <!-- Summary KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_clients.total_customers') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_customers || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_clients.active_customers') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ stats?.active_customers || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('s_clients.total_outstanding_balances') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-rose-500 font-mono">{{ formatCurrency(stats?.total_receivables) }}</p>
                </div>
            </div>

            <!-- Filter Panel -->
            <transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div v-if="isFilterOpen" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-sm space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Search -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('s_clients.search_by_name_code_phone_or_tax') }}</label>
                            <input 
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text" 
                                :placeholder="$t('s_clients.type_to_search')"
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Type -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('s_clients.customer_type') }}</label>
                            <select 
                                v-model="customerType" 
                                class="w-full text-xs rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">{{ $t('s_clients.all_individual_and_business') }}</option>
                                <option value="commercial">{{ $t('s_clients.business') }}</option>
                                <option value="individual">{{ $t('s_clients.individual') }}</option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('s_clients.status') }}</label>
                            <select 
                                v-model="status" 
                                class="w-full text-xs rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">{{ $t('s_clients.all') }}</option>
                                <option value="active">{{ $t('s_clients.active') }}</option>
                                <option value="inactive">{{ $t('s_clients.disabled') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button 
                            @click="resetFilters" 
                            class="px-4 py-2 text-xs font-medium text-slate-600 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors"
                        >
                            {{ $t('s_clients.reset') }}
                        </button>
                        <button 
                            @click="applyFilters" 
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors"
                        >
                            {{ $t('s_clients.apply_filter') }}
                        </button>
                    </div>
                </div>
            </transition>

            <!-- Clients Table Container -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">{{ $t('s_clients.customer_and_code') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('s_clients.type') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('s_clients.contact_details') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('s_clients.balance') }}</th>
                                <th class="py-4 px-6 text-center w-24">{{ $t('s_clients.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                            <tr 
                                v-for="c in customers.data" 
                                :key="c.id"
                                class="hover:bg-slate-50/70 transition-colors group"
                            >
                                <!-- Client Name & Code -->
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            @click="openViewModal(c)"
                                            class="font-bold text-slate-800 hover:text-indigo-600 transition-colors text-start"
                                            :title="$t('s_clients.view_customer_details')"
                                        >
                                            {{ c.name }}
                                        </button>
                                        <span class="text-xs font-mono text-slate-400">
                                            #{{ c.code || ('1-' + String(c.id).padStart(6, '0')) }}
                                        </span>
                                    </div>
                                    <div v-if="c.tax_number || c.commercial_registration" class="flex items-center gap-3 text-2xs text-slate-400 mt-1 font-mono">
                                        <span v-if="c.tax_number">{{ $t('s_clients.tax_no') }}: {{ c.tax_number }}</span>
                                        <span v-if="c.commercial_registration">{{ $t('s_clients.cr') }}: {{ c.commercial_registration }}</span>
                                    </div>
                                </td>

                                <!-- Type -->
                                <td class="py-4 px-6 whitespace-nowrap">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-2xs font-bold border',
                                            c.customer_type === 'individual'
                                                ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                : 'bg-indigo-50 text-indigo-700 border-indigo-200'
                                        ]"
                                    >
                                        <UserIcon v-if="c.customer_type === 'individual'" class="w-3 h-3" />
                                        <BuildingOfficeIcon v-else class="w-3 h-3" />
                                        <span>{{ c.customer_type === 'individual' ? $t('s_clients.individual') : $t('s_clients.business') }}</span>
                                    </span>
                                </td>

                                <!-- Contact Info -->
                                <td class="py-4 px-6">
                                    <div class="space-y-0.5 text-xs text-slate-600">
                                        <div v-if="c.contact_name" class="font-medium text-slate-700">
                                            {{ c.contact_name }}
                                        </div>
                                        <div v-if="c.phone || c.mobile" class="flex items-center gap-1.5 text-slate-500 font-mono text-2xs">
                                            <PhoneIcon class="w-3 h-3 text-slate-400" />
                                            <span>{{ c.phone || c.mobile }}</span>
                                        </div>
                                        <div v-if="c.city" class="text-2xs text-slate-400">
                                            {{ c.city }}
                                        </div>
                                    </div>
                                </td>

                                <!-- Balance -->
                                <td class="py-4 px-6 whitespace-nowrap">
                                    <span 
                                        :class="[
                                            'font-bold font-mono',
                                            Number(c.current_balance) > 0 ? 'text-rose-600' : 'text-slate-500'
                                        ]"
                                    >
                                        {{ formatCurrency(c.current_balance) }}
                                    </span>
                                </td>

                                <!-- Actions Dropdown & Quick Buttons -->
                                <td class="py-4 px-6 text-center" @click.stop>
                                    <div class="flex items-center justify-center gap-1">
                                        <!-- View Modal Button -->
                                        <button
                                            type="button"
                                            @click="openViewModal(c)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                                            :title="$t('s_clients.view_customer_2')"
                                        >
                                            <EyeIcon class="w-4 h-4" />
                                        </button>

                                        <!-- Edit Modal Button -->
                                        <button
                                            type="button"
                                            @click="openEditModal(c)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors"
                                            :title="$t('s_clients.edit_customer')"
                                        >
                                            <PencilSquareIcon class="w-4 h-4" />
                                        </button>

                                        <!-- Statement Link -->
                                        <Link
                                            :href="route('sales.clients.statement', c.id)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors"
                                            :title="$t('s_clients.account_statement')"
                                        >
                                            <DocumentTextIcon class="w-4 h-4" />
                                        </Link>

                                        <!-- More Actions Popover -->
                                        <div class="relative">
                                            <button 
                                                @click="toggleMenu(c.id)"
                                                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                                :title="$t('s_clients.more_options')"
                                            >
                                                <EllipsisVerticalIcon class="w-4 h-4" />
                                            </button>

                                            <div 
                                                v-if="activeMenu === c.id"
                                                class="absolute end-0 top-8 w-44 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-30 animate-in fade-in zoom-in-95 duration-100 text-xs font-medium text-slate-700"
                                            >
                                                <button 
                                                    @click="openViewModal(c); closeMenu()"
                                                    class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-indigo-600 transition-colors text-start"
                                                >
                                                    <EyeIcon class="w-4 h-4 text-slate-400" />
                                                    <span>{{ $t('s_clients.view_customer') }}</span>
                                                </button>
                                                <button 
                                                    @click="openEditModal(c); closeMenu()"
                                                    class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-emerald-600 transition-colors text-start"
                                                >
                                                    <PencilSquareIcon class="w-4 h-4 text-slate-400" />
                                                    <span>{{ $t('s_clients.edit_details') }}</span>
                                                </button>
                                                <Link 
                                                    :href="route('sales.clients.statement', c.id)"
                                                    class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-amber-600 transition-colors text-start"
                                                >
                                                    <DocumentTextIcon class="w-4 h-4 text-slate-400" />
                                                    <span>{{ $t('s_clients.statement') }}</span>
                                                </Link>
                                                <div class="h-px bg-slate-100 my-1"></div>
                                                <button 
                                                    @click="deleteCustomer(c.id, c.name)"
                                                    class="w-full flex items-center gap-2 px-4 py-2 text-rose-600 hover:bg-rose-50 transition-colors text-start"
                                                >
                                                    <TrashIcon class="w-4 h-4 text-rose-500" />
                                                    <span>{{ $t('s_clients.delete_customer') }}</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!customers.data || customers.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <UsersIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">{{ $t('s_clients.no_customers_added_yet') }}</p>
                                        <button 
                                            @click="openAddModal"
                                            class="mt-2 text-xs font-bold text-indigo-600 hover:text-indigo-800"
                                        >
                                            {{ $t('s_clients.add_the_first_customer_now') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                    <p>
                        {{ $t('s_clients.showing') }} <span class="font-bold text-slate-700">{{ customers.from || 0 }}</span> {{ $t('s_clients.to') }} <span class="font-bold text-slate-700">{{ customers.to || 0 }}</span> {{ $t('s_clients.of') }} <span class="font-bold text-slate-700">{{ customers.total || 0 }}</span> {{ $t('s_clients.customers') }}
                    </p>
                    <div v-if="customers?.links && customers.links.length > 3" class="flex items-center gap-1">
                        <Link 
                            v-for="(link, i) in customers.links" 
                            :key="i"
                            :href="link.url || '#'"
                            v-html="link.label"
                            :class="[
                                'px-3 py-1.5 text-xs rounded-lg transition-all',
                                link.active ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'text-slate-600 hover:bg-slate-100',
                                !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                            ]"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

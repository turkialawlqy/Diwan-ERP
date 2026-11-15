<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    PencilSquareIcon,
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    EllipsisVerticalIcon,
    EyeIcon,
    TrashIcon,
    ChevronLeftIcon,
    DocumentTextIcon,
    TruckIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationCircleIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    editableBranches: { type: Array, default: () => [] },
    invoices: Object,
    filters: Object,
    stats: Object,
});

// Bills of branches without government e-invoicing can be edited / deleted (the server checks the rest).
const changeable = (inv) => inv.source !== 'grn' && !inv.purchase_order_id && props.editableBranches.includes(inv.branch_id);
const removeInvoice = (inv) => {
    if (confirm(t('p_invoices_index.confirm_delete', { number: inv.invoice_number }))) router.delete(route('purchases.invoices.destroy', inv.id), { preserveScroll: true });
};

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const activeMenu = ref(null);

const toggleMenu = (id) => {
    activeMenu.value = activeMenu.value === id ? null : id;
};

const applyFilters = () => {
    router.get(route('purchases.invoices.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilters = () => {
    search.value = '';
    status.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    router.get(route('purchases.invoices.index'), {}, { preserveState: true, replace: true });
};

const formatCurrency = (val) => {
    return Number(val || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('p_invoices_index.purchase_invoices')" />

        <div class="space-y-5" @click="activeMenu = null">
            <!-- Header Ribbon -->
            <PageHeader year-scoped :title="$t('p_invoices_index.purchase_invoices_2')" :crumbs="[{ label: 'المشتريات' }, { label: 'فواتير الشراء' }]">
<template #actions>
<button 
                        @click="router.reload()"
                        :title="$t('p_invoices_index.refresh_data')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<button 
                        @click.stop="isFilterOpen = !isFilterOpen"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-medium text-xs sm:text-sm transition-all duration-150 shadow-xs border',
                            isFilterOpen || search || status
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700' 
                                : 'bg-slate-100/90 border-slate-200 text-slate-700 hover:bg-slate-200/80'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('p_invoices_index.search_and_filter') }}</span>
                    </button>

                    
                    <Link 
                        v-if="$canRoute('purchases.invoices.create')"
                        :href="route('purchases.invoices.create')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('p_invoices_index.add') }}</span>
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('p_invoices_index.search_by_invoice_number_or_supp') }}</label>
                            <input 
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text" 
                                :placeholder="$t('p_invoices_index.bill_26_or_supplier_name')" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('p_invoices_index.payment_status') }}</label>
                            <select 
                                v-model="status" 
                                class="w-full text-xs rounded-xl border border-slate-200 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">{{ $t('p_invoices_index.all_statuses') }}</option>
                                <option value="paid">{{ $t('p_invoices_index.fully_paid') }}</option>
                                <option value="partially_paid">{{ $t('p_invoices_index.partially_paid') }}</option>
                                <option value="unpaid">{{ $t('p_invoices_index.unpaid_due') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('p_invoices_index.from_date') }}</label>
                            <DatePicker 
                                v-model="dateFrom" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ $t('p_invoices_index.to_date') }}</label>
                            <DatePicker 
                                v-model="dateTo" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button 
                            @click="resetFilters" 
                            class="px-4 py-2 text-xs font-medium text-slate-600 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors"
                        >
                            {{ $t('p_invoices_index.reset') }}
                        </button>
                        <button 
                            @click="applyFilters" 
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors"
                        >
                            {{ $t('p_invoices_index.apply_filter') }}
                        </button>
                    </div>
                </div>
            </transition>

            <!-- KPI Mini Summary Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('p_invoices_index.total_invoices') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_invoices_count || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('p_invoices_index.total_purchases') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ formatCurrency(stats?.total_purchases_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('p_invoices_index.paid_to_suppliers') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ formatCurrency(stats?.total_paid_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('p_invoices_index.remaining_payable') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-rose-500">{{ formatCurrency(stats?.total_due_amount) }} <span class="text-xs font-normal text-slate-500">{{ $cur() }}</span></p>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">{{ $t('p_invoices_index.invoice_number') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('p_invoices_index.supplier') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('p_invoices_index.warehouse') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('p_invoices_index.invoice_date') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('p_invoices_index.total_including_tax') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('p_invoices_index.paid_remaining') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('p_invoices_index.payment_status') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('p_invoices_index.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                            <tr 
                                v-for="inv in invoices.data" 
                                :key="inv.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    <Link :href="route('purchases.invoices.show', inv.id)" class="hover:underline">
                                        {{ inv.invoice_number }}
                                    </Link>
                                    <div v-if="inv.supplier_invoice_number" class="text-4xs text-slate-400 font-sans">
                                        {{ $t('p_invoices_index.ref') }}: {{ inv.supplier_invoice_number }}
                                    </div>
                                </td>
                                <td class="py-4 px-6 font-bold text-slate-900">
                                    {{ inv.supplier?.name || '-' }}
                                </td>
                                <td class="py-4 px-6 text-slate-600">
                                    {{ inv.warehouse?.name_ar || $t('p_invoices_index.main_wh') }}
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-600 whitespace-nowrap">
                                    {{ String(inv.invoice_date).substring(0, 10) }}
                                </td>
                                <td class="py-4 px-6 font-bold text-slate-800 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-slate-400 font-semibold text-xs">﷼</span>
                                        <span>{{ formatCurrency(inv.total) }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-6 font-mono whitespace-nowrap">
                                    <div class="text-emerald-700 font-bold text-xs">{{ formatCurrency(inv.paid_amount) }} ﷼</div>
                                    <div v-if="inv.remaining_amount > 0" class="text-rose-600 font-bold text-xs">{{ $t('s_payments.remaining') }}: {{ formatCurrency(inv.remaining_amount) }} ﷼</div>
                                </td>
                                <td class="py-4 px-6 text-center whitespace-nowrap">
                                    <span 
                                        v-if="inv.payment_status === 'paid'"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/80"
                                    >
                                        {{ $t('p_invoices_index.paid') }}
                                    </span>
                                    <span 
                                        v-else-if="inv.payment_status === 'partially_paid'"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/80"
                                    >
                                        {{ $t('p_invoices_index.partially_paid') }}
                                    </span>
                                    <span 
                                        v-else
                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200/80"
                                    >
                                        {{ $t('p_invoices_index.payable') }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <Link 
                                        :href="route('purchases.invoices.show', inv.id)"
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors inline-block"
                                        :title="$t('p_invoices_index.view_invoice')"
                                    >
                                        <EyeIcon class="w-4 h-4" />
                                    </Link>
                                    <Link v-if="changeable(inv) && $can('purchases.invoices.edit')" :href="route('purchases.invoices.edit', inv.id)" class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 inline-block" :title="$t('p_invoices_index.edit')"><PencilSquareIcon class="w-4 h-4" /></Link>
                                    <button v-if="changeable(inv) && $can('purchases.invoices.delete')" type="button" @click="removeInvoice(inv)" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 inline-block" :title="$t('p_invoices_index.delete')"><TrashIcon class="w-4 h-4" /></button>
                                </td>
                            </tr>
                            <tr v-if="!invoices.data || invoices.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <DocumentTextIcon class="w-10 h-10 text-slate-300 stroke-1" />
                                        <p class="font-semibold text-slate-500">{{ $t('p_invoices_index.no_purchase_invoices_recorded') }}</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>{{ $t('s_invoices.total_n', { n: invoices.total || 0 }) }}</span>
                        <span>{{ $t('p_invoices_index.per_page') }}</span>
                        <select 
                            :value="invoices.per_page"
                            @change="router.get(route('purchases.invoices.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>{{ $t('s_invoices.page_of', { a: invoices.current_page, b: invoices.last_page || 1 }) }}</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="invoices.prev_page_url" 
                                :href="invoices.prev_page_url"
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
                                {{ invoices.current_page }}
                            </span>
                            <Link 
                                v-if="invoices.next_page_url" 
                                :href="invoices.next_page_url"
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

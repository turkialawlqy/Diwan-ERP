<script setup>
import { useI18n } from 'vue-i18n';
import RowActions from '@/Components/UI/RowActions.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import {
    PlusIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    DocumentCheckIcon,
    ClockIcon,
    CheckCircleIcon,
    ArrowRightCircleIcon,
    TruckIcon,
    FunnelIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    orders: Object,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');

const handleSearch = () => {
    router.get(route('purchases.orders.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilter = () => {
    search.value = '';
    status.value = '';
    handleSearch();
};

const convertToInvoice = (order) => {
    if (confirm(t('p_orders_index.confirm_convert', { number: order.order_number }))) {
        router.post(route('purchases.orders.convert', order.id));
    }
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
        <Head :title="$t('p_orders_index.purchase_orders')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader year-scoped :title="$t('p_orders_index.purchase_orders')" :crumbs="[{ label: 'المشتريات' }, { label: 'أوامر الشراء' }]">
<template #actions>
<button 
                        @click="handleSearch"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        :title="$t('p_orders_index.refresh_data')"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
<Link v-if="$can('purchases.orders.create')" :href="route('purchases.orders.create')" class="px-3.5 py-2.5 rounded-xl bg-indigo-600 text-white text-xs font-bold flex items-center gap-2"><PlusIcon class="w-4 h-4" /><span>{{ $t('p_orders_index.purchase_order') }}</span></Link>
                    <button 
                        @click="isFilterOpen = !isFilterOpen"
                        :class="[
                            isFilterOpen ? 'bg-indigo-50 border-indigo-200 text-indigo-600' : 'bg-white border-slate-200/80 text-slate-600 hover:bg-slate-50',
                            'px-3.5 py-2.5 rounded-xl border text-xs font-bold transition flex items-center gap-2 shadow-xs'
                        ]"
                    >
                        <FunnelIcon class="w-4 h-4" />
                        <span>{{ $t('p_orders_index.filter') }}</span>
                    </button>
</template>
</PageHeader>

            <!-- Collapsible Filter Panel -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="transform -translate-y-2 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform -translate-y-2 opacity-0"
            >
                <div v-show="isFilterOpen" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <!-- Search Query -->
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">{{ $t('p_orders_index.search_by_purchase_order_or_supp') }}</label>
                            <div class="relative">
                                <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                                <input 
                                    v-model="search"
                                    @keyup.enter="handleSearch"
                                    type="text" 
                                    :placeholder="$t('p_orders_index.order_number_supplier_name')" 
                                    class="w-full ps-9 pe-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                                />
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">{{ $t('p_orders_index.order_status') }}</label>
                            <select 
                                v-model="status" 
                                @change="handleSearch"
                                class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                            >
                                <option value="">{{ $t('p_orders_index.all_statuses') }}</option>
                                <option value="pending">{{ $t('p_orders_index.pending') }}</option>
                                <option value="approved">{{ $t('p_orders_index.approved') }}</option>
                                <option value="converted_to_invoice">{{ $t('p_orders_index.converted_to_an_invoice') }}</option>
                                <option value="draft">{{ $t('p_orders_index.draft') }}</option>
                                <option value="pending_approval">{{ $t('p_orders_index.awaiting_approval') }}</option>
                                <option value="partially_received">{{ $t('p_orders_index.partially_received') }}</option>
                                <option value="received">{{ $t('p_orders_index.received') }}</option>
                                <option value="cancelled">{{ $t('p_orders_index.cancelled') }}</option>
                            </select>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-end gap-2">
                            <button 
                                @click="handleSearch"
                                class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-xs"
                            >
                                {{ $t('p_orders_index.apply') }}
                            </button>
                            <button 
                                @click="resetFilter"
                                class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition"
                                :title="$t('p_orders_index.reset')"
                            >
                                <ArrowPathIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">{{ $t('p_orders_index.total_purchase_orders') }}</span>
                    <h3 class="text-xl font-black text-slate-900 mt-1">{{ stats?.total_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">{{ $t('p_orders_index.pending_orders') }}</span>
                    <h3 class="text-xl font-black text-amber-600 mt-1">{{ stats?.pending_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">{{ $t('p_orders_index.approved_orders') }}</span>
                    <h3 class="text-xl font-black text-indigo-600 mt-1">{{ stats?.approved_orders || 0 }}</h3>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <span class="text-xs font-bold text-slate-500">{{ $t('p_orders_index.total_order_value') }}</span>
                    <h3 class="text-xl font-black text-emerald-600 font-mono mt-1">{{ formatCurrency(stats?.total_orders_value) }} <span class="text-xs font-normal text-slate-400">﷼</span></h3>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6">{{ $t('p_orders_index.order_number') }}</th>
                                <th class="py-4 px-6">{{ $t('p_orders_index.supplier') }}</th>
                                <th class="py-4 px-6">{{ $t('p_orders_index.order_date') }}</th>
                                <th class="py-4 px-6">{{ $t('p_orders_index.expected_delivery') }}</th>
                                <th class="py-4 px-6">{{ $t('p_orders_index.total_value') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('p_orders_index.status') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('p_orders_index.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="order in orders.data" 
                                :key="order.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-4 px-6 font-mono font-bold text-indigo-600">
                                    {{ order.order_number }}
                                </td>
                                <td class="py-4 px-6 font-bold text-slate-900">
                                    {{ order.supplier?.name || '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ String(order.order_date).substring(0, 10) }}
                                </td>
                                <td class="py-4 px-6 font-mono text-xs text-slate-600">
                                    {{ order.expected_delivery_date ? String(order.expected_delivery_date).substring(0, 10) : '-' }}
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900">
                                    {{ formatCurrency(order.total) }} <span class="text-xs font-normal">﷼</span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <StatusBadge :status="order.status" />
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <Link :href="route('purchases.orders.show', order.id)" class="px-3 py-1 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs me-1">{{ $t('p_orders_index.open') }}</Link>
                                    <RowActions :edit="['draft', 'rejected'].includes(order.status) && $can('purchases.orders.edit') ? route('purchases.orders.edit', order.id) : null" :remove="['draft', 'pending_approval', 'approved', 'rejected', 'cancelled'].includes(order.status) && $can('purchases.orders.cancel') ? route('purchases.orders.destroy', order.id) : null" :confirm="`نقل أمر الشراء ${order.order_number} إلى سلة المحذوفات؟`" />
                                    <button 
                                        v-if="['pending', 'approved'].includes(order.status) && $can('purchases.orders.convert')"
                                        @click="convertToInvoice(order)"
                                        class="px-3 py-1 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs transition-colors inline-flex items-center gap-1"
                                    >
                                        <ArrowRightCircleIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                                        <span>{{ $t('p_orders_index.convert_to_a_purchase_invoice') }}</span>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!orders.data || orders.data.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    {{ $t('p_orders_index.no_purchase_orders_recorded') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="orders?.links && orders.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        {{ $t('p_orders_index.showing') }} <span class="font-bold text-slate-700">{{ orders.from || 0 }}</span> {{ $t('p_orders_index.to') }} <span class="font-bold text-slate-700">{{ orders.to || 0 }}</span> {{ $t('p_orders_index.of') }} <span class="font-bold text-slate-700">{{ orders.total }}</span> {{ $t('p_orders_index.orders') }}
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in orders.links" :key="i">
                            <Link 
                                v-if="link.url"
                                :href="link.url"
                                :class="[
                                    link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50',
                                    'min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs transition-colors'
                                ]"
                                v-html="link.label"
                            />
                            <span 
                                v-else
                                class="min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

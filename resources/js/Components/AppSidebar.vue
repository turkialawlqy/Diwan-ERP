<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AlOstazLogo from './AlOstazLogo.vue';
import {
    HomeIcon,
    ShoppingCartIcon,
    DeviceTabletIcon,
    ArchiveBoxIcon,
    TruckIcon,
    BanknotesIcon,
    BookOpenIcon,
    ChartBarIcon,
    UsersIcon,
    IdentificationIcon,
    BuildingStorefrontIcon,
    PrinterIcon,
    Cog6ToothIcon,
    ChevronDownIcon,
    XMarkIcon,
    ShieldCheckIcon
} from '@heroicons/vue/24/outline';

defineProps({
    isOpen: {
        type: Boolean,
        default: false,
    }
});

defineEmits(['closeSidebar']);

const page = usePage();
const currentUrl = ref(window.location.pathname);

// Collapsible menus state
const openMenus = ref({
    sales: true,
    pos: false,
    inventory: false,
    purchases: false,
    finance: false,
    accounting: false,
    reports: false,
    hr: false,
    settings: false,
});

const toggleMenu = (menuKey) => {
    openMenus.value[menuKey] = !openMenus.value[menuKey];
};

const isCurrent = (path) => {
    return window.location.pathname === path || window.location.pathname.startsWith(path + '/');
};
</script>

<template>
    <!-- Mobile Backdrop -->
    <div 
        v-if="isOpen" 
        @click="$emit('closeSidebar')"
        class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden transition-opacity"
    ></div>

    <!-- Sidebar Container (Right side in RTL) -->
    <aside 
        :class="[
            isOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0',
            'fixed top-0 bottom-0 end-0 w-64 bg-white border-s border-slate-200 z-50 flex flex-col transition-transform duration-300 ease-in-out shadow-lg lg:shadow-none'
        ]"
    >
        <!-- Sidebar Brand Header -->
        <div class="h-16 flex items-center justify-between px-5 border-b border-slate-100 flex-shrink-0">
            <Link :href="route('dashboard')" class="flex items-center">
                <AlOstazLogo size="sm" :showSubtitle="true" />
            </Link>

            <button 
                @click="$emit('closeSidebar')" 
                class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
            >
                <XMarkIcon class="w-5 h-5" />
            </button>
        </div>

        <!-- Sidebar Navigation Items (Scrollable) -->
        <div class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-xs select-none custom-scrollbar">
            <!-- 1. لوحة التحكم -->
            <Link 
                :href="route('dashboard')"
                :class="[
                    isCurrent('/dashboard') || isCurrent('/')
                        ? 'bg-indigo-50 text-indigo-700 font-bold'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium',
                    'flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors'
                ]"
            >
                <HomeIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                <span>{{ $t('nav.dashboard') }}</span>
            </Link>

            <!-- 2. المبيعات (Collapsible) -->
            <div>
                <button 
                    @click="toggleMenu('sales')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <ShoppingCartIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.sales') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.sales ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.sales" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('sales.invoices.index')"
                        :class="[isCurrent('/sales/invoices') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.invoices') }}
                    </Link>
                    <Link 
                        :href="route('sales.quotations.index')"
                        :class="[isCurrent('/sales/quotations') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.quotations') }}
                    </Link>
                    <Link 
                        :href="route('sales.returns.index')"
                        :class="[isCurrent('/sales/returns') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.credit_notes') }}
                    </Link>
                    <Link 
                        :href="route('sales.clients.index')"
                        :class="[isCurrent('/sales/clients') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.clients') }}
                    </Link>
                    <Link 
                        :href="route('sales.payments.index')"
                        :class="[isCurrent('/sales/payments') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.payments_received') }}
                    </Link>
                </div>
            </div>

            <!-- 3. نقاط البيع (POS) -->
            <div>
                <button 
                    @click="toggleMenu('pos')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <DeviceTabletIcon class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.pos') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.pos ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.pos" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('pos.index')"
                        :class="[isCurrent('/pos') ? 'text-emerald-600 font-bold bg-emerald-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.pos_session') }}
                    </Link>
                    <Link 
                        :href="route('pos.history')"
                        :class="[isCurrent('/pos/history') ? 'text-emerald-600 font-bold bg-emerald-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.pos_history') }}
                    </Link>
                </div>
            </div>

            <!-- 4. المخزون (Collapsible) -->
            <div>
                <button 
                    @click="toggleMenu('inventory')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <ArchiveBoxIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.inventory') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.inventory ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.inventory" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('inventory.products.index')"
                        :class="[isCurrent('/inventory/products') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.products') }}
                    </Link>
                    <Link 
                        :href="route('inventory.categories.index')"
                        :class="[isCurrent('/inventory/categories') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.categories') }}
                    </Link>
                    <Link 
                        :href="route('inventory.operations.index')"
                        :class="[isCurrent('/inventory/operations') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.inventory_operations') }}
                    </Link>
                    <Link 
                        :href="route('inventory.warehouses.index')"
                        :class="[isCurrent('/inventory/warehouses') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.warehouses') }}
                    </Link>
                    <Link 
                        :href="route('inventory.counts.index')"
                        :class="[isCurrent('/inventory/counts') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.inventory_count') }}
                    </Link>
                </div>
            </div>

            <!-- 5. المشتريات (Collapsible) -->
            <div>
                <button 
                    @click="toggleMenu('purchases')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <TruckIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.purchases') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.purchases ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.purchases" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('purchases.orders.index')"
                        :class="[isCurrent('/purchases/orders') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.purchase_orders') }}
                    </Link>
                    <Link 
                        :href="route('purchases.invoices.index')"
                        :class="[isCurrent('/purchases/invoices') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.purchase_invoices') }}
                    </Link>
                    <Link 
                        :href="route('purchases.suppliers.index')"
                        :class="[isCurrent('/purchases/suppliers') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.suppliers') }}
                    </Link>
                    <Link 
                        :href="route('purchases.payments.index')"
                        :class="[isCurrent('/purchases/payments') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.supplier_payments') }}
                    </Link>
                </div>
            </div>

            <!-- 6. المالية (Collapsible) -->
            <div>
                <button 
                    @click="toggleMenu('finance')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <BanknotesIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.finance') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.finance ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.finance" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('finance.expenses.index')"
                        :class="[isCurrent('/finance/expenses') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.expenses') }}
                    </Link>
                    <Link 
                        :href="route('finance.revenues.index')"
                        :class="[isCurrent('/finance/revenues') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.revenues') }}
                    </Link>
                    <Link 
                        :href="route('finance.banks.index')"
                        :class="[isCurrent('/finance/banks') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.treasury_banks') }}
                    </Link>
                </div>
            </div>

            <!-- 7. المحاسبة (Collapsible) -->
            <div>
                <button 
                    @click="toggleMenu('accounting')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <BookOpenIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.accounting') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.accounting ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.accounting" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('accounting.accounts.index')"
                        :class="[isCurrent('/accounting/accounts') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.chart_of_accounts') }}
                    </Link>
                    <Link 
                        :href="route('accounting.journals.index')"
                        :class="[isCurrent('/accounting/journals') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.journal_entries') }}
                    </Link>
                    <Link 
                        :href="route('accounting.ledger.index')"
                        :class="[isCurrent('/accounting/ledger') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.general_ledger') }}
                    </Link>
                    <Link 
                        :href="route('accounting.trial-balance.index')"
                        :class="[isCurrent('/accounting/trial-balance') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.trial_balance') }}
                    </Link>
                </div>
            </div>

            <!-- 8. التقارير (Collapsible) -->
            <div>
                <button 
                    @click="toggleMenu('reports')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <ChartBarIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.reports') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.reports ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.reports" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('reports.sales')"
                        :class="[isCurrent('/reports/sales') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.sales_reports') }}
                    </Link>
                    <Link 
                        :href="route('reports.inventory')"
                        :class="[isCurrent('/reports/inventory') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.inventory_reports') }}
                    </Link>
                    <Link 
                        :href="route('reports.financial')"
                        :class="[isCurrent('/reports/financial') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.financial_reports') }}
                    </Link>
                    <Link 
                        :href="route('reports.vat')"
                        :class="[isCurrent('/reports/vat') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.vat_reports') }}
                    </Link>
                </div>
            </div>

            <!-- 9. المستخدمين والصلاحيات -->
            <Link 
                :href="route('users.index')"
                :class="[
                    isCurrent('/users')
                        ? 'bg-indigo-50 text-indigo-700 font-bold'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium',
                    'flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors'
                ]"
            >
                <UsersIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                <span>{{ $t('nav.users_permissions') }}</span>
            </Link>

            <!-- 10. الموارد البشرية -->
            <div>
                <button 
                    @click="toggleMenu('hr')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <IdentificationIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.hr') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.hr ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.hr" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('hr.employees.index')"
                        :class="[isCurrent('/hr/employees') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.employees') }}
                    </Link>
                    <Link 
                        :href="route('hr.payroll.index')"
                        :class="[isCurrent('/hr/payroll') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.payroll') }}
                    </Link>
                </div>
            </div>

            <!-- 11. الفروع -->
            <Link 
                :href="route('branches.index')"
                :class="[
                    isCurrent('/branches')
                        ? 'bg-indigo-50 text-indigo-700 font-bold'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium',
                    'flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors'
                ]"
            >
                <BuildingStorefrontIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                <span>{{ $t('nav.branches') }}</span>
            </Link>

            <!-- 12. قوالب الطباعة -->
            <Link 
                :href="route('templates.index')"
                :class="[
                    isCurrent('/templates')
                        ? 'bg-indigo-50 text-indigo-700 font-bold'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium',
                    'flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors'
                ]"
            >
                <PrinterIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                <span>{{ $t('nav.templates') }}</span>
            </Link>

            <!-- 13. الإعدادات -->
            <div>
                <button 
                    @click="toggleMenu('settings')"
                    class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <Cog6ToothIcon class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                        <span class="font-semibold">{{ $t('nav.settings') }}</span>
                    </div>
                    <ChevronDownIcon 
                        :class="[openMenus.settings ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']" 
                    />
                </button>

                <div v-show="openMenus.settings" class="mt-1 ps-8 pe-2 space-y-1">
                    <Link 
                        :href="route('settings.index')"
                        :class="[isCurrent('/settings') ? 'text-indigo-600 font-bold bg-indigo-50/50' : 'text-slate-500 hover:text-slate-900', 'block px-2.5 py-1.5 rounded-lg transition']"
                    >
                        {{ $t('nav.general_settings') }}
                    </Link>
                </div>
            </div>

            <!-- Super Admin Link (if Super Admin) -->
            <div v-if="page.props.auth?.user?.is_super_admin" class="pt-3 border-t border-slate-100">
                <Link 
                    :href="route('super-admin.dashboard')"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-purple-50 text-purple-700 font-bold hover:bg-purple-100 transition-colors"
                >
                    <ShieldCheckIcon class="w-5 h-5 text-purple-600 flex-shrink-0" />
                    <span>لوحة تحكم النظام العام</span>
                </Link>
            </div>
        </div>

        <!-- Sidebar Footer Info -->
        <div class="p-3 border-t border-slate-100 text-center text-[10px] text-slate-400 flex-shrink-0">
            <span>نظام الأستاذ v1.0.0 © 2026</span>
        </div>
    </aside>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #E2E8F0;
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #CBD5E1;
}
</style>

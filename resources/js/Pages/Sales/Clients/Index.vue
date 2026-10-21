<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CustomerModal from './CustomerModal.vue';
import CustomerViewModal from './CustomerViewModal.vue';
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

// Modal states
const isAddEditModalOpen = ref(false);
const editingCustomer = ref(null);

const isViewModalOpen = ref(false);
const viewingCustomer = ref(null);

const openAddModal = () => {
    editingCustomer.value = null;
    isAddEditModalOpen.value = true;
};

const openEditModal = (client) => {
    editingCustomer.value = client;
    isAddEditModalOpen.value = true;
};

const openViewModal = async (client) => {
    viewingCustomer.value = { ...client };
    isViewModalOpen.value = true;

    // Load full relationships (invoices, payments, quotations)
    try {
        const res = await axios.get(route('sales.clients.show', client.id), {
            headers: { 'Accept': 'application/json' },
        });
        if (res.data?.client) {
            viewingCustomer.value = res.data.client;
        }
    } catch (err) {
        console.error('Failed to load full customer details', err);
    }
};

const handleCustomerSaved = () => {
    isAddEditModalOpen.value = false;
    router.reload({ preserveScroll: true });
};

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
    if (confirm(`هل أنت متأكد من حذف العميل ${name}؟`)) {
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
        <Head title="إدارة العملاء" />

        <div class="space-y-5" @click="closeMenu" dir="rtl">
            <!-- Top Breadcrumbs & Action Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs Ribbon -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">العملاء</span>
                    </div>

                    <ImportButton v-if="$can('sales.clients.create')" type="customers" title="استيراد العملاء من Excel" hint="عمود opening_balance اختياري: رصيد العميل الافتتاحي (للعملاء الجدد فقط)." />
                    <!-- Refresh Button -->
                    <button 
                        @click="router.reload()" 
                        class="p-2 bg-white border border-slate-200/80 rounded-xl text-slate-400 hover:text-indigo-600 hover:border-indigo-100 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3">
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
                        <span>تصفية وبحث</span>
                    </button>

                    <button
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة عميل</span>
                    </button>
                </div>
            </div>

            <!-- Summary KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي العملاء</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_customers || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">العملاء النشطون</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ stats?.active_customers || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي الأرصدة المستحقة</p>
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
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">بحث بالاسم أو الكود أو الهاتف أو الضريبي</label>
                            <input 
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text" 
                                placeholder="اكتب للبحث..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Type -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">نوع العميل</label>
                            <select 
                                v-model="customerType" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">الكل (فردي وتجاري)</option>
                                <option value="commercial">تجاري</option>
                                <option value="individual">فردي</option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">الحالة</label>
                            <select 
                                v-model="status" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="">الكل</option>
                                <option value="active">نشط</option>
                                <option value="inactive">معطل</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button 
                            @click="resetFilters" 
                            class="px-4 py-2 text-xs font-medium text-slate-600 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition-colors"
                        >
                            إعادة ضبط
                        </button>
                        <button 
                            @click="applyFilters" 
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition-colors"
                        >
                            تطبيق التصفية
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
                                <th class="py-4 px-6 text-start">العميل والكود</th>
                                <th class="py-4 px-6 text-start">النوع</th>
                                <th class="py-4 px-6 text-start">بيانات الاتصال</th>
                                <th class="py-4 px-6 text-start">الرصيد</th>
                                <th class="py-4 px-6 text-center w-24">إجراءات</th>
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
                                            title="عرض تفاصيل العميل"
                                        >
                                            {{ c.name }}
                                        </button>
                                        <span class="text-xs font-mono text-slate-400">
                                            #{{ c.code || ('1-' + String(c.id).padStart(6, '0')) }}
                                        </span>
                                    </div>
                                    <div v-if="c.tax_number || c.commercial_registration" class="flex items-center gap-3 text-2xs text-slate-400 mt-1 font-mono">
                                        <span v-if="c.tax_number">الرقم الضريبي: {{ c.tax_number }}</span>
                                        <span v-if="c.commercial_registration">السجل: {{ c.commercial_registration }}</span>
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
                                        <span>{{ c.customer_type === 'individual' ? 'فردي' : 'تجاري' }}</span>
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
                                            title="عرض عميل"
                                        >
                                            <EyeIcon class="w-4 h-4" />
                                        </button>

                                        <!-- Edit Modal Button -->
                                        <button
                                            type="button"
                                            @click="openEditModal(c)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors"
                                            title="تعديل العميل"
                                        >
                                            <PencilSquareIcon class="w-4 h-4" />
                                        </button>

                                        <!-- Statement Link -->
                                        <Link
                                            :href="route('sales.clients.statement', c.id)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors"
                                            title="كشف حساب"
                                        >
                                            <DocumentTextIcon class="w-4 h-4" />
                                        </Link>

                                        <!-- More Actions Popover -->
                                        <div class="relative">
                                            <button 
                                                @click="toggleMenu(c.id)"
                                                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                                title="خيارات إضافية"
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
                                                    <span>عرض العميل</span>
                                                </button>
                                                <button 
                                                    @click="openEditModal(c); closeMenu()"
                                                    class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-emerald-600 transition-colors text-start"
                                                >
                                                    <PencilSquareIcon class="w-4 h-4 text-slate-400" />
                                                    <span>تعديل البيانات</span>
                                                </button>
                                                <Link 
                                                    :href="route('sales.clients.statement', c.id)"
                                                    class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-amber-600 transition-colors text-start"
                                                >
                                                    <DocumentTextIcon class="w-4 h-4 text-slate-400" />
                                                    <span>كشف الحساب</span>
                                                </Link>
                                                <div class="h-px bg-slate-100 my-1"></div>
                                                <button 
                                                    @click="deleteCustomer(c.id, c.name)"
                                                    class="w-full flex items-center gap-2 px-4 py-2 text-rose-600 hover:bg-rose-50 transition-colors text-start"
                                                >
                                                    <TrashIcon class="w-4 h-4 text-rose-500" />
                                                    <span>حذف العميل</span>
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
                                        <p class="font-semibold text-slate-500">لا يوجد عملاء مضافين حتى الآن</p>
                                        <button 
                                            @click="openAddModal"
                                            class="mt-2 text-xs font-bold text-indigo-600 hover:text-indigo-800"
                                        >
                                            + إضافة أول عميل الآن
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
                        عرض <span class="font-bold text-slate-700">{{ customers.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ customers.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ customers.total || 0 }}</span> عميل
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

        <!-- Add/Edit Customer Modal (Exact matching screenshots 2 & 3) -->
        <CustomerModal
            :show="isAddEditModalOpen"
            :customer="editingCustomer"
            :nextCode="nextCode"
            @close="isAddEditModalOpen = false"
            @saved="handleCustomerSaved"
        />

        <!-- View Customer Modal (Exact matching screenshot 1) -->
        <CustomerViewModal
            :show="isViewModalOpen"
            :customer="viewingCustomer"
            @close="isViewModalOpen = false"
            @edit="openEditModal"
        />
    </AppLayout>
</template>

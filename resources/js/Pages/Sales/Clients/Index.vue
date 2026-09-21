<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
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
    XMarkIcon,
    ChevronLeftIcon,
    UserPlusIcon,
    UsersIcon,
    CheckIcon,
    PhoneIcon,
    EnvelopeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    customers: Object,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
const isAddModalOpen = ref(false);
const editingCustomer = ref(null);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const activeMenu = ref(null);

const form = useForm({
    name: '',
    company_name: '',
    phone: '',
    email: '',
    tax_number: '',
    commercial_registration: '',
    address: '',
    city: 'الرياض',
    postal_code: '',
    credit_limit: 0,
    opening_balance: 0,
    status: 'active',
    notes: '',
});

const openAddModal = () => {
    editingCustomer.value = null;
    form.reset();
    isAddModalOpen.value = true;
};

const openEditModal = (client) => {
    editingCustomer.value = client;
    form.name = client.name;
    form.company_name = client.company_name;
    form.phone = client.phone;
    form.email = client.email;
    form.tax_number = client.tax_number;
    form.commercial_registration = client.commercial_registration;
    form.address = client.address;
    form.city = client.city || 'الرياض';
    form.postal_code = client.postal_code;
    form.credit_limit = client.credit_limit;
    form.opening_balance = client.opening_balance;
    form.status = client.status;
    form.notes = client.notes;
    isAddModalOpen.value = true;
};

const submitCustomer = () => {
    if (editingCustomer.value) {
        form.put(route('sales.clients.update', editingCustomer.value.id), {
            onSuccess: () => {
                isAddModalOpen.value = false;
                form.reset();
            }
        });
    } else {
        form.post(route('sales.clients.store'), {
            onSuccess: () => {
                isAddModalOpen.value = false;
                form.reset();
            }
        });
    }
};

const toggleMenu = (id) => {
    activeMenu.value = activeMenu.value === id ? null : id;
};

const closeMenu = () => {
    activeMenu.value = null;
};

const applyFilters = () => {
    router.get(route('sales.clients.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilters = () => {
    search.value = '';
    status.value = '';
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
    if (!val || Number(val) === 0) return '-';
    return Number(val).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ﷼';
};
</script>

<template>
    <AppLayout>
        <Head title="إدارة العملاء" />

        <div class="space-y-5" @click="closeMenu">
            <!-- Top Breadcrumbs & Action Bar (Matching live screenshot) -->
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

                    <!-- Refresh Button -->
                    <button 
                        @click="router.reload()" 
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <!-- Search & Filter Toggle Button -->
                    <button 
                        @click.stop="isFilterOpen = !isFilterOpen"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-medium text-xs sm:text-sm transition-all duration-150 shadow-xs border',
                            isFilterOpen 
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700' 
                                : 'bg-slate-100/90 border-slate-200 text-slate-700 hover:bg-slate-200/80'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4 text-indigo-600" />
                        <span>البحث والتصفية</span>
                    </button>

                    <!-- Add Client Button -->
                    <button 
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة</span>
                    </button>
                </div>
            </div>

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
                        <!-- Search Keyword -->
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">اسم العميل، الهاتف، أو الرقم الضريبي</label>
                            <input 
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text" 
                                placeholder="بحث عن عميل..."
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            />
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

            <!-- Clients Table Container (Exact visual match to live system) -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">الاسم</th>
                                <th class="py-4 px-6 text-start">الرصيد</th>
                                <th class="py-4 px-6 text-center w-12">
                                    <span class="sr-only">إجراءات</span>
                                </th>
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
                                        <span class="font-bold text-slate-800 hover:text-indigo-600 transition-colors cursor-pointer" @click="openEditModal(c)">
                                            {{ c.name }}
                                        </span>
                                        <span class="text-xs font-mono text-slate-400">
                                            #1-{{ String(c.id).padStart(6, '0') }}
                                        </span>
                                    </div>
                                    <div v-if="c.phone || c.tax_number" class="flex items-center gap-3 text-2xs text-slate-400 mt-1">
                                        <span v-if="c.phone" class="flex items-center gap-1">
                                            <PhoneIcon class="w-3 h-3 text-slate-300" />
                                            <span>{{ c.phone }}</span>
                                        </span>
                                        <span v-if="c.tax_number" class="font-mono">
                                            الرقم الضريبي: {{ c.tax_number }}
                                        </span>
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

                                <!-- Actions Dropdown -->
                                <td class="py-4 px-6 text-center relative" @click.stop>
                                    <button 
                                        @click="toggleMenu(c.id)"
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"
                                        title="خيارات"
                                    >
                                        <EllipsisVerticalIcon class="w-5 h-5" />
                                    </button>

                                    <!-- Popover Menu -->
                                    <div 
                                        v-if="activeMenu === c.id"
                                        class="absolute start-0 sm:start-auto end-6 top-12 w-44 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-30 animate-in fade-in zoom-in-95 duration-100 text-xs font-medium text-slate-700"
                                    >
                                        <button 
                                            @click="openEditModal(c); closeMenu()"
                                            class="w-full flex items-center gap-2 px-4 py-2 hover:bg-slate-50 hover:text-indigo-600 transition-colors text-start"
                                        >
                                            <PencilSquareIcon class="w-4 h-4 text-slate-400" />
                                            <span>تعديل البيانات</span>
                                        </button>
                                        <div class="h-px bg-slate-100 my-1"></div>
                                        <button 
                                            @click="deleteCustomer(c.id, c.name)"
                                            class="w-full flex items-center gap-2 px-4 py-2 text-rose-600 hover:bg-rose-50 transition-colors text-start"
                                        >
                                            <TrashIcon class="w-4 h-4 text-rose-500" />
                                            <span>حذف العميل</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!customers.data || customers.data.length === 0">
                                <td colspan="3" class="py-12 text-center text-slate-400">
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

                <!-- Live-Style Pagination Footer -->
                <div class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ customers.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
                        <select 
                            :value="customers.per_page"
                            @change="router.get(route('sales.clients.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>

                    <!-- Page Navigation -->
                    <div class="flex items-center gap-2">
                        <span>الصفحة {{ customers.current_page }} من {{ customers.last_page || 1 }}.</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="customers.prev_page_url" 
                                :href="customers.prev_page_url"
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
                                {{ customers.current_page }}
                            </span>

                            <Link 
                                v-if="customers.next_page_url" 
                                :href="customers.next_page_url"
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

        <!-- Add/Edit Customer Modal Dialog -->
        <div 
            v-if="isAddModalOpen" 
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-100 space-y-6 animate-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
                            <UserPlusIcon class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-base font-black text-slate-800">
                                {{ editingCustomer ? 'تعديل بيانات العميل' : 'إضافة عميل جديد' }}
                            </h3>
                            <p class="text-2xs text-slate-400">سجل بيانات العميل لإصدار الفواتير وعروض الأسعار</p>
                        </div>
                    </div>
                    <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600 p-1">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitCustomer" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Customer Name -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required
                                placeholder="مثال: شركة مدن الراقية..." 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Company Name -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">اسم المؤسسة / التجاري</label>
                            <input 
                                v-model="form.company_name" 
                                type="text" 
                                placeholder="اسم السجل التجاري..." 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Phone -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                            <input 
                                v-model="form.phone" 
                                type="text" 
                                placeholder="05XXXXXXXX" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                            <input 
                                v-model="form.email" 
                                type="email" 
                                placeholder="client@company.com" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Tax Number (15 digits Saudi VAT) -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الضريبي (15 رقم)</label>
                            <input 
                                v-model="form.tax_number" 
                                type="text" 
                                maxlength="15"
                                placeholder="3XXXXXXXXXXXX03" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Commercial Registration -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">رقم السجل التجاري</label>
                            <input 
                                v-model="form.commercial_registration" 
                                type="text" 
                                placeholder="1010XXXXXX" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- City & Address -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">المدينة</label>
                            <input 
                                v-model="form.city" 
                                type="text" 
                                placeholder="الرياض" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">العنوان الوطني / الحي</label>
                            <input 
                                v-model="form.address" 
                                type="text" 
                                placeholder="حي العليا - شارع التحلية..." 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <!-- Opening Balance (Only on create) -->
                        <div v-if="!editingCustomer">
                            <label class="block text-xs font-bold text-slate-700 mb-1">الرصيد الافتتاحي (ر.س)</label>
                            <input 
                                v-model="form.opening_balance" 
                                type="number" 
                                step="0.01" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <!-- Credit Limit -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الحد الائتماني (ر.س)</label>
                            <input 
                                v-model="form.credit_limit" 
                                type="number" 
                                step="0.01" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                        <button 
                            type="button" 
                            @click="isAddModalOpen = false" 
                            class="px-5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all disabled:opacity-50"
                        >
                            {{ form.processing ? 'جاري الحفظ...' : (editingCustomer ? 'حفظ التعديلات' : 'إضافة العميل') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

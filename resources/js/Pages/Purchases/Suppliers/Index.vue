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
    PencilSquareIcon,
    TrashIcon,
    XMarkIcon,
    ChevronLeftIcon,
    TruckIcon,
    CheckIcon,
    PhoneIcon,
    EnvelopeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    suppliers: Object,
    filters: Object,
    stats: Object,
});

const isFilterOpen = ref(false);
const isAddModalOpen = ref(false);
const editingSupplier = ref(null);
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const activeMenu = ref(null);

const form = useForm({
    name: '',
    company_name: '',
    phone: '',
    email: '',
    tax_number: '',
    commercial_register: '',
    address: '',
    bank_name: '',
    iban: '',
    opening_balance: 0,
    status: 'active',
});

const openAddModal = () => {
    editingSupplier.value = null;
    form.reset();
    isAddModalOpen.value = true;
};

const openEditModal = (supplier) => {
    editingSupplier.value = supplier;
    form.name = supplier.name;
    form.company_name = supplier.company_name;
    form.phone = supplier.phone;
    form.email = supplier.email;
    form.tax_number = supplier.tax_number;
    form.commercial_register = supplier.commercial_register;
    form.address = supplier.address;
    form.bank_name = supplier.bank_name;
    form.iban = supplier.iban;
    form.opening_balance = supplier.opening_balance;
    form.status = supplier.status;
    isAddModalOpen.value = true;
};

const submitSupplier = () => {
    if (editingSupplier.value) {
        form.put(route('purchases.suppliers.update', editingSupplier.value.id), {
            onSuccess: () => {
                isAddModalOpen.value = false;
                form.reset();
            }
        });
    } else {
        form.post(route('purchases.suppliers.store'), {
            onSuccess: () => {
                isAddModalOpen.value = false;
                form.reset();
            }
        });
    }
};

const deleteSupplier = (supplier) => {
    if (confirm(`هل أنت متأكد من حذف المورد "${supplier.name}"؟`)) {
        router.delete(route('purchases.suppliers.destroy', supplier.id));
    }
};

const handleSearch = () => {
    router.get(route('purchases.suppliers.index'), {
        search: search.value,
        status: status.value,
    }, { preserveState: true, replace: true });
};

const resetFilter = () => {
    search.value = '';
    status.value = '';
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
    <AppLayout>
        <Head title="إدارة الموردين - الأستاذ" />

        <div class="space-y-5" @click="activeMenu = null">
            <!-- 1. Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة مورد</span>
                    </button>

                    <button 
                        @click="isFilterOpen = !isFilterOpen"
                        :class="[
                            'px-4 py-2 rounded-xl text-xs font-semibold border transition-colors flex items-center gap-1.5',
                            isFilterOpen || search || status
                                ? 'bg-indigo-50 border-indigo-200 text-indigo-700 font-bold' 
                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                        ]"
                    >
                        <MagnifyingGlassIcon class="w-4 h-4" />
                        <span>البحث والتصفية</span>
                    </button>
                </div>

                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleSearch"
                        class="p-2 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-2xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-3 py-1.5 text-xs font-medium text-slate-600 shadow-2xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                        <span class="text-slate-400">المشتريات</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-1.5 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">الموردين</span>
                    </div>
                </div>
            </div>

            <!-- Filter Panel -->
            <div v-show="isFilterOpen" class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-3xs font-bold text-slate-500 mb-1">بحث بالاسم / الشركة / الهاتف / الضريبي</label>
                        <input 
                            v-model="search"
                            @keyup.enter="handleSearch"
                            type="text" 
                            placeholder="اكتب للبحث..." 
                            class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500 font-medium"
                        />
                    </div>
                    <div>
                        <label class="block text-3xs font-bold text-slate-500 mb-1">الحالة</label>
                        <select 
                            v-model="status" 
                            class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500 font-medium"
                        >
                            <option value="">جميع الحالات</option>
                            <option value="active">نشط</option>
                            <option value="inactive">غير نشط</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button 
                            @click="handleSearch"
                            class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-colors"
                        >
                            تطبيق
                        </button>
                        <button 
                            @click="resetFilter"
                            class="px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl text-xs font-medium transition-colors"
                        >
                            إعادة تعيين
                        </button>
                    </div>
                </div>
            </div>

            <!-- KPI Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-2xs font-bold text-slate-400">إجمالي الموردين</p>
                        <h3 class="text-lg font-black text-slate-900 mt-0.5">{{ stats?.total_suppliers || 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <TruckIcon class="w-5 h-5" />
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-2xs font-bold text-slate-400">المستحقات الحالية للموردين</p>
                        <h3 class="text-lg font-black text-rose-600 font-mono mt-0.5">{{ formatCurrency(stats?.total_payable) }} ﷼</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-sm">
                        ﷼
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-2xs font-bold text-slate-400">الموردين النشطين</p>
                        <h3 class="text-lg font-black text-emerald-600 mt-0.5">{{ stats?.active_suppliers || 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <CheckIcon class="w-5 h-5 stroke-2" />
                    </div>
                </div>
            </div>

            <!-- Suppliers Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-200/80 text-slate-500 font-bold">
                                <th class="py-3 px-4">اسم المورد</th>
                                <th class="py-3 px-4">الشركة والنشاط</th>
                                <th class="py-3 px-4">الرقم الضريبي</th>
                                <th class="py-3 px-4">الهاتف / التواصل</th>
                                <th class="py-3 px-4">الرصيد المستحق</th>
                                <th class="py-3 px-4 text-center">الحالة</th>
                                <th class="py-3 px-4 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr 
                                v-for="supplier in suppliers.data" 
                                :key="supplier.id"
                                class="hover:bg-slate-50/50 transition-colors"
                            >
                                <td class="py-3.5 px-4 font-bold text-slate-900">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-700 font-bold flex items-center justify-center text-xs flex-shrink-0">
                                            {{ supplier.name.charAt(0) }}
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold text-slate-900">{{ supplier.name }}</div>
                                            <div class="text-3xs text-slate-400 font-mono">#SUP-{{ String(supplier.id).padStart(5, '0') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-slate-600">
                                    {{ supplier.company_name || '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-600">
                                    {{ supplier.tax_number || '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-3xs text-slate-600">
                                    <div class="flex items-center gap-1.5">
                                        <PhoneIcon class="w-3.5 h-3.5 text-slate-400" />
                                        <span>{{ supplier.phone || '-' }}</span>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold">
                                    <span :class="supplier.current_balance > 0 ? 'text-rose-600' : 'text-slate-800'">
                                        {{ formatCurrency(supplier.current_balance) }} <span class="text-3xs font-normal">﷼</span>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span 
                                        :class="[
                                            'px-2 py-0.5 rounded-full text-3xs font-bold inline-block',
                                            supplier.status === 'active' 
                                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/60' 
                                                : 'bg-slate-100 text-slate-600'
                                        ]"
                                    >
                                        {{ supplier.status === 'active' ? 'نشط' : 'معطل' }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button 
                                            @click="openEditModal(supplier)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                                            title="تعديل"
                                        >
                                            <PencilSquareIcon class="w-4 h-4" />
                                        </button>
                                        <button 
                                            @click="deleteSupplier(supplier)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors"
                                            title="حذف"
                                        >
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!suppliers.data || suppliers.data.length === 0">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    لا يوجد موردين مسجلين حتى الآن.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add / Edit Supplier Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-xl w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <TruckIcon class="w-5 h-5 text-indigo-600" />
                            <span>{{ editingSupplier ? 'تعديل بيانات المورد' : 'إضافة مورد جديد' }}</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitSupplier" class="space-y-4 text-xs">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">اسم المورد <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.name" 
                                    type="text" 
                                    required 
                                    placeholder="اسم المورد أو الشركة" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">الاسم التجاري / الشركة</label>
                                <input 
                                    v-model="form.company_name" 
                                    type="text" 
                                    placeholder="الاسم التجاري" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">الرقم الضريبي (15 رقم)</label>
                                <input 
                                    v-model="form.tax_number" 
                                    type="text" 
                                    maxlength="15" 
                                    placeholder="300000000000003" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">رقم السجل التجاري</label>
                                <input 
                                    v-model="form.commercial_register" 
                                    type="text" 
                                    placeholder="1010XXXXXX" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">رقم الهاتف</label>
                                <input 
                                    v-model="form.phone" 
                                    type="text" 
                                    placeholder="05XXXXXXXX" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                                <input 
                                    v-model="form.email" 
                                    type="email" 
                                    placeholder="vendor@company.com" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">اسم البنك</label>
                                <input 
                                    v-model="form.bank_name" 
                                    type="text" 
                                    placeholder="مصرف الراجحي / البنك الأهلي" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">رقم الآيبان (IBAN)</label>
                                <input 
                                    v-model="form.iban" 
                                    type="text" 
                                    placeholder="SA0000000000000000000000" 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">العنوان الوطني / المقر</label>
                            <input 
                                v-model="form.address" 
                                type="text" 
                                placeholder="المدينة، الحي، اسم الشارع..." 
                                class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                            />
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                            <button 
                                type="button" 
                                @click="isAddModalOpen = false" 
                                class="px-4 py-2 border border-slate-200 rounded-xl hover:bg-slate-50 text-slate-600 font-semibold"
                            >
                                إلغاء
                            </button>
                            <button 
                                type="submit" 
                                :disabled="form.processing" 
                                class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                            >
                                {{ editingSupplier ? 'تحديث البيانات' : 'حفظ المورد' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

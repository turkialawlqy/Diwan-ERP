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
    UsersIcon,
    XMarkIcon,
    TrashIcon,
    BanknotesIcon,
    BriefcaseIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    employees: Object,
    stats: Object,
    branches: Array,
    filters: Object,
});

const isAddModalOpen = ref(false);
const search = ref(props.filters?.search || '');
const selectedDepartment = ref(props.filters?.department || '');

const form = useForm({
    name: '',
    national_id: '',
    phone: '',
    email: '',
    job_title: '',
    department: 'المبيعات',
    branch_id: props.branches?.[0]?.id || '',
    hire_date: new Date().toISOString().split('T')[0],
    basic_salary: '',
    housing_allowance: '',
    transport_allowance: '',
    other_allowances: '0',
    bank_name: 'مصرف الراجحي',
    iban: '',
    status: 'active',
    notes: '',
});

const openAddModal = () => {
    form.reset();
    form.branch_id = props.branches?.[0]?.id || '';
    form.hire_date = new Date().toISOString().split('T')[0];
    isAddModalOpen.value = true;
};

const submitEmployee = () => {
    form.post(route('hr.employees.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const deleteEmployee = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف الموظف "${name}"؟`)) {
        router.delete(route('hr.employees.destroy', id));
    }
};

const handleSearch = () => {
    router.get(route('hr.employees.index'), {
        search: search.value || undefined,
        department: selectedDepartment.value || undefined,
    }, { preserveState: true, replace: true });
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
        <Head title="الموظفون والموارد البشرية - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="handleSearch"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">الموارد البشرية</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">الموظفون</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة موظف جديد</span>
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">إجمالي الموظفين</span>
                        <h3 class="text-lg font-black text-slate-900">{{ stats?.total_employees || 0 }} موظف</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <UsersIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">الموظفون على رأس العمل</span>
                        <h3 class="text-lg font-black text-emerald-600">{{ stats?.active_employees || 0 }} موظف</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BriefcaseIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500">إجمالي مسير الرواتب الشهري</span>
                        <h3 class="text-lg font-black font-mono text-indigo-600">
                            {{ formatCurrency(stats?.total_monthly_payroll) }} <span class="text-xs font-normal text-slate-400">﷼</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Search and Filter Bar -->
            <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="relative w-full sm:w-80">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2" />
                    <input 
                        v-model="search"
                        @keyup.enter="handleSearch"
                        type="text" 
                        placeholder="بحث بالاسم، المسمى، القسم، الهوية..."
                        class="w-full ps-9 pe-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                    />
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <select 
                        v-model="selectedDepartment"
                        @change="handleSearch"
                        class="w-full sm:w-48 py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700 focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        <option value="">جميع الأقسام</option>
                        <option v-for="dept in stats.departments" :key="dept" :value="dept">{{ dept }}</option>
                    </select>
                </div>
            </div>

            <!-- Employees Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">اسم الموظف</th>
                                <th class="py-4 px-6 text-start">المسمى الوظيفي</th>
                                <th class="py-4 px-6 text-start">القسم</th>
                                <th class="py-4 px-6 text-start">الهاتف / الهوية</th>
                                <th class="py-4 px-6 text-start">الفرع</th>
                                <th class="py-4 px-6 text-end">الراتب الإجمالي</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="emp in employees.data" :key="emp.id" class="hover:bg-slate-50/50 transition">
                                <td class="py-4 px-6 font-bold text-slate-900">
                                    {{ emp.name }}
                                </td>
                                <td class="py-4 px-6 text-slate-700 font-medium">
                                    {{ emp.job_title }}
                                </td>
                                <td class="py-4 px-6">
                                    <span class="px-2.5 py-0.5 rounded-full text-3xs font-semibold bg-slate-100 text-slate-700">
                                        {{ emp.department }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 font-mono text-3xs text-slate-500">
                                    <div>{{ emp.phone || '-' }}</div>
                                    <div class="text-slate-400">{{ emp.national_id }}</div>
                                </td>
                                <td class="py-4 px-6 text-slate-600 text-3xs">
                                    {{ emp.branch?.name_ar || 'الفرع الرئيسي' }}
                                </td>
                                <td class="py-4 px-6 font-mono font-bold text-slate-900 text-end">
                                    {{ formatCurrency(emp.total_salary) }} ﷼
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span 
                                        :class="[
                                            emp.status === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200',
                                            'px-2.5 py-1 rounded-full text-xs font-bold border'
                                        ]"
                                    >
                                        {{ emp.status === 'active' ? 'نشط' : 'إجازة' }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <button 
                                        @click="deleteEmployee(emp.id, emp.name)"
                                        class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                        title="حذف"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!employees.data || employees.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    لا يوجد موظفون مسجلون.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="employees?.links && employees.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ employees.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ employees.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ employees.total }}</span> موظف
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in employees.links" :key="i">
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

            <!-- Add Employee Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-xl w-full p-6 space-y-4 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <UsersIcon class="w-5 h-5 text-indigo-600" />
                            <span>إضافة موظف جديد في المنظومة</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitEmployee" class="space-y-4 text-xs">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">اسم الموظف الرباعي <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.name" 
                                    type="text" 
                                    required 
                                    placeholder="الاسم الكامل"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الهوية الوطنية / الإقامة</label>
                                <input 
                                    v-model="form.national_id" 
                                    type="text" 
                                    placeholder="10 أرقام"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">المسمى الوظيفي <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.job_title" 
                                    type="text" 
                                    required 
                                    placeholder="مثال: مدير مبيعات، محاسب..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">القسم / الإدارة <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.department" 
                                    type="text" 
                                    required 
                                    placeholder="المبيعات، المالية، التقنية..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">رقم الجوال</label>
                                <input 
                                    v-model="form.phone" 
                                    type="text" 
                                    placeholder="05XXXXXXXX"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">البريد الإلكتروني</label>
                                <input 
                                    v-model="form.email" 
                                    type="email" 
                                    placeholder="name@company.com"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">الفرع التابع له</label>
                                <select 
                                    v-model="form.branch_id" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name_ar }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">تاريخ المباشرة / التعيين</label>
                                <input 
                                    v-model="form.hire_date" 
                                    type="date" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>

                        <!-- Salary Package -->
                        <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/80 space-y-3">
                            <span class="font-bold text-slate-900 block">تفاصيل باقة الراتب الشهري (﷼):</span>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-3xs font-medium text-slate-600 mb-0.5">الراتب الأساسي *</label>
                                    <input 
                                        v-model="form.basic_salary" 
                                        type="number" 
                                        step="0.01" 
                                        required 
                                        placeholder="0.00"
                                        class="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg font-mono text-end"
                                    />
                                </div>
                                <div>
                                    <label class="block text-3xs font-medium text-slate-600 mb-0.5">بدل السكن</label>
                                    <input 
                                        v-model="form.housing_allowance" 
                                        type="number" 
                                        step="0.01" 
                                        placeholder="0.00"
                                        class="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg font-mono text-end"
                                    />
                                </div>
                                <div>
                                    <label class="block text-3xs font-medium text-slate-600 mb-0.5">بدل النقل</label>
                                    <input 
                                        v-model="form.transport_allowance" 
                                        type="number" 
                                        step="0.01" 
                                        placeholder="0.00"
                                        class="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg font-mono text-end"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">اسم البنك</label>
                                <input 
                                    v-model="form.bank_name" 
                                    type="text" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">رقم الآيبان (IBAN)</label>
                                <input 
                                    v-model="form.iban" 
                                    type="text" 
                                    placeholder="SA..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono" 
                                />
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                            <button 
                                type="button" 
                                @click="isAddModalOpen = false"
                                class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium"
                            >
                                إلغاء
                            </button>
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                            >
                                حفظ بيانات الموظف
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

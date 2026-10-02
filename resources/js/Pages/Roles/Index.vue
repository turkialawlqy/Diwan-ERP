<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ShieldCheckIcon,
    UserGroupIcon,
    XMarkIcon,
    TrashIcon,
    CheckBadgeIcon,
    LockClosedIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    roles: Array,
});

const isModalOpen = ref(false);

const form = useForm({
    name: '',
});

const openAddModal = () => {
    form.reset();
    isModalOpen.value = true;
};

const submitRole = () => {
    form.post(route('roles.store'), {
        onSuccess: () => {
            isModalOpen.value = false;
            form.reset();
        }
    });
};

const deleteRole = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف الدور "${name}"؟`)) {
        router.delete(route('roles.destroy', id));
    }
};

const getRoleDetails = (name) => {
    const map = {
        admin: {
            title: 'مدير النظام الكامل (Admin)',
            desc: 'صلاحيات وصول كاملة لجميع الشاشات والإعدادات والتقارير والعمليات المحاسبية',
            color: 'indigo',
        },
        accountant: {
            title: 'المحاسب المالي (Accountant)',
            desc: 'إدارة القيود اليومية، شجرة الحسابات، ميزان المراجعة، المصروفات والإيرادات',
            color: 'blue',
        },
        sales: {
            title: 'مسؤول المبيعات (Sales Rep)',
            desc: 'إصدار الفواتير وعروض الأسعار وإدارة بيانات العملاء والتحصيلات',
            color: 'emerald',
        },
        cashier: {
            title: 'كاشير نقاط البيع (POS Cashier)',
            desc: 'تشغيل نقطة البيع السريعة، فتح وإغلاق الصندوق، إصدار الإيصالات المبسطة',
            color: 'amber',
        },
        employee: {
            title: 'موظف قياسي (Employee)',
            desc: 'عرض الملف الشخصي ومسيرات الرواتب والإشعارات الأساسية',
            color: 'slate',
        },
    };
    return map[name] || {
        title: name,
        desc: 'دور مخصص إضافي تم تعريفه للمنشأة',
        color: 'purple',
    };
};
</script>

<template>
    <AppLayout>
        <Head title="الأدوار والصلاحيات - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">الأدوار والصلاحيات</span>
                    </div>
                    <Link 
                        :href="route('roles.index')"
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('users.index')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs sm:text-sm shadow-xs transition-all"
                    >
                        <UserGroupIcon class="w-4 h-4 text-indigo-600" />
                        <span>إدارة المستخدمين</span>
                    </Link>
                    <button 
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة دور</span>
                    </button>
                </div>
            </div>

            <!-- KPI Mini Summary Bar -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي الأدوار</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ roles?.length || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">الأدوار القياسية (نظامية)</p>
                    <p class="text-xl sm:text-2xl font-black text-indigo-600">{{ roles?.filter(r => r.is_system).length || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">الأدوار المخصصة</p>
                    <p class="text-xl sm:text-2xl font-black text-amber-600">{{ roles?.filter(r => !r.is_system).length || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">المستخدمين المرتبطين</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ roles?.reduce((sum, r) => sum + (r.users_count || 0), 0) || 0 }}</p>
                </div>
            </div>

            <!-- Roles Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="role in roles" 
                    :key="role.id"
                    class="bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md transition-all p-5 flex flex-col justify-between"
                >
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                    <ShieldCheckIcon class="w-6 h-6" />
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 text-sm">
                                        {{ getRoleDetails(role.name).title }}
                                    </h3>
                                    <span class="font-mono text-[11px] text-slate-400">{{ role.name }}</span>
                                </div>
                            </div>

                            <span 
                                v-if="role.is_system"
                                class="inline-flex items-center gap-1 bg-slate-100 text-slate-600 text-[10px] font-bold px-2 py-0.5 rounded-full"
                            >
                                <LockClosedIcon class="w-3 h-3 text-slate-400" />
                                <span>نظامي</span>
                            </span>
                        </div>

                        <p class="text-xs text-slate-500 leading-relaxed mt-2">
                            {{ getRoleDetails(role.name).desc }}
                        </p>
                    </div>

                    <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-1.5 text-xs text-slate-600 font-bold">
                            <UserGroupIcon class="w-4 h-4 text-slate-400" />
                            <span>{{ role.users_count }} مستخدم مرتبط</span>
                        </div>

                        <button 
                            v-if="!role.is_system"
                            @click="deleteRole(role.id, role.name)"
                            class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                            title="حذف الدور"
                        >
                            <TrashIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Custom Role Modal -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs">
            <div class="bg-white rounded-3xl w-full max-w-md shadow-2xl border border-slate-100 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="font-black text-slate-900 text-sm">إضافة دور وظيفي مخصص</h3>
                    <button @click="isModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitRole" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">اسم الدور (بالإنجليزي) *</label>
                        <input 
                            v-model="form.name" 
                            type="text" 
                            required
                            placeholder="e.g. inventory_manager"
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                            dir="ltr"
                        />
                        <p v-if="form.errors.name" class="text-[11px] text-rose-600 mt-1">{{ form.errors.name }}</p>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                        <button 
                            type="button" 
                            @click="isModalOpen = false"
                            class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition disabled:opacity-50"
                        >
                            إنشاء الدور
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

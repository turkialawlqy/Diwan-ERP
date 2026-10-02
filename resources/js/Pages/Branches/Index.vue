<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BuildingOfficeIcon,
    XMarkIcon,
    TrashIcon,
    MapPinIcon,
    PhoneIcon,
    StarIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    branches: Array,
    stats: Object,
});

const isAddModalOpen = ref(false);

const form = useForm({
    name_ar: '',
    name_en: '',
    code: '',
    city: 'الرياض',
    phone: '',
    address: '',
    is_main: false,
});

const openAddModal = () => {
    form.reset();
    isAddModalOpen.value = true;
};

const submitBranch = () => {
    form.post(route('branches.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        }
    });
};

const deleteBranch = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف الفرع "${name}"؟`)) {
        router.delete(route('branches.destroy', id));
    }
};
</script>

<template>
    <AppLayout>
        <Head title="الفروع - الأستاذ" />

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
                        <span class="text-indigo-600 font-bold">الفروع</span>
                    </div>
                    <Link 
                        :href="route('branches.index')"
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة فرع</span>
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي فروع المنشأة</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-800">{{ stats?.total_branches || 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">الفروع العاملة والنشطة</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ stats?.active_branches || 0 }}</p>
                </div>
            </div>

            <!-- Branches Cards Grid -->
            <div v-if="branches && branches.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="branch in branches" 
                    :key="branch.id"
                    class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs hover:shadow-md transition-shadow relative overflow-hidden flex flex-col justify-between"
                >
                    <div class="space-y-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                    <BuildingOfficeIcon class="w-5 h-5" />
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-bold text-slate-900 text-sm">{{ branch.name_ar }}</h4>
                                        <span v-if="branch.is_main" class="px-2 py-0.5 rounded-full text-3xs font-bold bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-0.5">
                                            <StarIcon class="w-3 h-3 fill-amber-500" />
                                            <span>رئيسي</span>
                                        </span>
                                    </div>
                                    <span class="text-3xs font-mono font-bold text-indigo-600">{{ branch.code }}</span>
                                </div>
                            </div>

                            <button 
                                v-if="!branch.is_main"
                                @click="deleteBranch(branch.id, branch.name_ar)"
                                class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                title="حذف"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>

                        <!-- Details -->
                        <div class="space-y-2 p-3 bg-slate-50 rounded-xl text-3xs text-slate-600">
                            <div class="flex items-center gap-2">
                                <MapPinIcon class="w-4 h-4 text-slate-400 flex-shrink-0" />
                                <span>{{ branch.city }} - {{ branch.address || 'لا يوجد عنوان مسجل' }}</span>
                            </div>
                            <div class="flex items-center gap-2 font-mono">
                                <PhoneIcon class="w-4 h-4 text-slate-400 flex-shrink-0" />
                                <span>{{ branch.phone || '-' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 mt-4 border-t border-slate-100 flex items-center justify-between text-3xs text-slate-500">
                        <span>الحالة:</span>
                        <span class="font-bold text-emerald-600">نشط ويعمل</span>
                    </div>
                </div>
            </div>

            <div v-else class="bg-white rounded-2xl border border-slate-200/80 p-12 text-center text-slate-400 shadow-2xs">
                <div class="flex flex-col items-center justify-center gap-2">
                    <BuildingOfficeIcon class="w-12 h-12 text-slate-300 stroke-1" />
                    <p class="font-semibold text-slate-600 text-sm">لا توجد فروع مسجلة حتى الآن</p>
                    <p class="text-xs text-slate-400">يمكنك إنشاء فرع جديد بالضغط على زر "إضافة فرع" بالأعلى</p>
                </div>
            </div>

            <!-- Add Branch Modal -->
            <div 
                v-if="isAddModalOpen" 
                class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
            >
                <div class="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl border border-slate-100">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                        <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            <BuildingOfficeIcon class="w-5 h-5 text-indigo-600" />
                            <span>إضافة فرع جديد للمنشأة</span>
                        </h2>
                        <button @click="isAddModalOpen = false" class="text-slate-400 hover:text-slate-600">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submitBranch" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">اسم الفرع بالعربية <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name_ar" 
                                type="text" 
                                required 
                                placeholder="مثال: فرع الخبر، فرع مكة..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">رمز الفرع (الكود) <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.code" 
                                    type="text" 
                                    required 
                                    placeholder="BR-04"
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">المدينة <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.city" 
                                    type="text" 
                                    required 
                                    placeholder="الرياض، جدة..."
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">رقم الهاتف / الجوال</label>
                            <input 
                                v-model="form.phone" 
                                type="text" 
                                placeholder="01XXXXXXXX"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono" 
                            />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">العنوان التفصيلي</label>
                            <input 
                                v-model="form.address" 
                                type="text" 
                                placeholder="اسم الشارع، الحي، رقم المبنى..."
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl" 
                            />
                        </div>

                        <div class="flex items-center gap-2 pt-2">
                            <input 
                                v-model="form.is_main" 
                                id="is_main" 
                                type="checkbox" 
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <label for="is_main" class="font-medium text-slate-700 cursor-pointer">
                                تعيين كفرع رئيسي للمنشأة
                            </label>
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
                                حفظ الفرع
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

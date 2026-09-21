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
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة فرع جديد</span>
                    </button>
                </div>

                <!-- Breadcrumb & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('branches.index')"
                        class="p-2 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-2xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>

                    <nav class="flex items-center gap-1.5 text-xs text-slate-500 bg-white border border-slate-200/80 px-3 py-1.5 rounded-xl shadow-2xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 transition-colors">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span class="text-slate-900 font-semibold">الفروع</span>
                    </nav>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">إجمالي فروع المنشأة</span>
                        <h3 class="text-lg font-black text-slate-900">{{ stats?.total_branches || 0 }} فروع</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BuildingOfficeIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-2xs flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-3xs font-bold text-slate-400">الفروع العاملة والنشطة</span>
                        <h3 class="text-lg font-black text-emerald-600">{{ stats?.active_branches || 0 }} فروع</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BuildingOfficeIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Branches Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="branch in branches" 
                    :key="branch.id"
                    class="bg-white rounded-2xl border border-slate-100 p-5 shadow-2xs hover:shadow-md transition-shadow relative overflow-hidden flex flex-col justify-between"
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

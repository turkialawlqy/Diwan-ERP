<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BuildingStorefrontIcon,
    XMarkIcon,
    TrashIcon,
    PencilSquareIcon,
    MapPinIcon,
    PhoneIcon,
    StarIcon,
    CubeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    warehouses: Array,
    branches: Array,
    stats: Object,
});

const isModalOpen = ref(false);
const editingWarehouse = ref(null);

const form = useForm({
    branch_id: '',
    name_ar: '',
    name_en: '',
    code: '',
    phone: '',
    address: '',
    is_main: false,
});

const openAddModal = () => {
    editingWarehouse.value = null;
    form.reset();
    form.branch_id = props.branches?.[0]?.id || '';
    isModalOpen.value = true;
};

const openEditModal = (wh) => {
    editingWarehouse.value = wh;
    form.branch_id = wh.branch_id || '';
    form.name_ar = wh.name_ar;
    form.name_en = wh.name_en || '';
    form.code = wh.code;
    form.phone = wh.phone || '';
    form.address = wh.address || '';
    form.is_main = !!wh.is_main;
    isModalOpen.value = true;
};

const submitWarehouse = () => {
    if (editingWarehouse.value) {
        form.put(route('inventory.warehouses.update', editingWarehouse.value.id), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            }
        });
    } else {
        form.post(route('inventory.warehouses.store'), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            }
        });
    }
};

const deleteWarehouse = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف المستودع "${name}"؟`)) {
        router.delete(route('inventory.warehouses.destroy', id));
    }
};
</script>

<template>
    <AppLayout>
        <Head title="المستودعات" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('inventory.warehouses.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المخزون</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المستودعات</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة مستودع جديد</span>
                    </button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">إجمالي المستودعات</p>
                        <h3 class="text-xl font-black text-slate-900">{{ stats?.total_warehouses ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">المستودعات النشطة</p>
                        <h3 class="text-xl font-black text-emerald-600">{{ stats?.active_warehouses ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">المستودع الرئيسي الحالي</p>
                        <h3 class="text-base font-bold text-slate-800 truncate max-w-[200px]">{{ stats?.main_warehouse ?? '—' }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <StarIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Warehouses Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="wh in warehouses" 
                    :key="wh.id"
                    class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs hover:shadow-md transition-all p-5 flex flex-col justify-between relative group"
                >
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-600 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors">
                                    <BuildingStorefrontIcon class="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                                        <span>{{ wh.name_ar }}</span>
                                        <span v-if="wh.is_main" class="bg-amber-100 text-amber-700 text-[10px] font-black px-2 py-0.5 rounded-full flex items-center gap-1">
                                            <StarIcon class="w-3 h-3 fill-amber-500 text-amber-500" />
                                            <span>رئيسي</span>
                                        </span>
                                    </h3>
                                    <p class="text-[11px] text-slate-400 font-mono">{{ wh.code }}</p>
                                </div>
                            </div>

                            <span 
                                :class="wh.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
                                class="text-[11px] font-bold px-2 py-0.5 rounded-lg border"
                            >
                                {{ wh.is_active ? 'نشط' : 'معطل' }}
                            </span>
                        </div>

                        <div class="space-y-2 mt-4 pt-3 border-t border-slate-100 text-xs text-slate-600">
                            <div v-if="wh.branch" class="flex items-center justify-between text-slate-500">
                                <span>الفرع التابع له:</span>
                                <span class="font-bold text-slate-800">{{ wh.branch?.name_ar }}</span>
                            </div>
                            <div class="flex items-center justify-between text-slate-500">
                                <span>عدد الأصناف المخزنة:</span>
                                <span class="font-bold text-indigo-600 flex items-center gap-1">
                                    <CubeIcon class="w-3.5 h-3.5" />
                                    <span>{{ wh.stocks_count ?? 0 }} صنف</span>
                                </span>
                            </div>
                            <div v-if="wh.phone" class="flex items-center gap-1.5 text-slate-500 pt-1">
                                <PhoneIcon class="w-3.5 h-3.5 text-slate-400" />
                                <span dir="ltr">{{ wh.phone }}</span>
                            </div>
                            <div v-if="wh.address" class="flex items-center gap-1.5 text-slate-500">
                                <MapPinIcon class="w-3.5 h-3.5 text-slate-400" />
                                <span class="truncate">{{ wh.address }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                        <button 
                            @click="openEditModal(wh)"
                            class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                            title="تعديل"
                        >
                            <PencilSquareIcon class="w-4 h-4" />
                        </button>
                        <button 
                            v-if="!wh.is_main"
                            @click="deleteWarehouse(wh.id, wh.name_ar)"
                            class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                            title="حذف"
                        >
                            <TrashIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <div 
                    v-if="warehouses.length === 0" 
                    class="col-span-full bg-white rounded-2xl border border-dashed border-slate-300 p-12 text-center"
                >
                    <BuildingStorefrontIcon class="w-12 h-12 text-slate-300 mx-auto mb-3" />
                    <h3 class="text-sm font-bold text-slate-800">لا يوجد مستودعات مسجلة حتى الآن</h3>
                    <p class="text-xs text-slate-400 mt-1 mb-4">ابدأ بإضافة أول مستودع لتتبع حركة الأصناف والمخزون</p>
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition"
                    >
                        إضافة مستودع
                    </button>
                </div>
            </div>
        </div>

        <!-- Add/Edit Warehouse Modal -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs">
            <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl border border-slate-100 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="font-black text-slate-900 text-sm">
                        {{ editingWarehouse ? 'تعديل بيانات المستودع' : 'إضافة مستودع جديد' }}
                    </h3>
                    <button @click="isModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitWarehouse" class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-slate-700 mb-1">اسم المستودع (بالعربي) *</label>
                            <input 
                                v-model="form.name_ar" 
                                type="text" 
                                required
                                placeholder="مثال: مستودع الرياض المركزي"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            />
                            <p v-if="form.errors.name_ar" class="text-[11px] text-rose-600 mt-1">{{ form.errors.name_ar }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الاسم (بالإنجليزي)</label>
                            <input 
                                v-model="form.name_en" 
                                type="text" 
                                placeholder="Warehouse Name"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">كود المستودع *</label>
                            <input 
                                v-model="form.code" 
                                type="text" 
                                required
                                placeholder="WH-01"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 uppercase font-mono"
                            />
                            <p v-if="form.errors.code" class="text-[11px] text-rose-600 mt-1">{{ form.errors.code }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الفرع المرتبط</label>
                            <select 
                                v-model="form.branch_id"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            >
                                <option value="">-- اختياري --</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name_ar }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">هاتف المستودع / المسؤول</label>
                            <input 
                                v-model="form.phone" 
                                type="text" 
                                placeholder="0500000000"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                            />
                        </div>

                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-slate-700 mb-1">العنوان أو الموقع</label>
                            <input 
                                v-model="form.address" 
                                type="text" 
                                placeholder="المنطقة الصناعية الثانية، الرياض"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            />
                        </div>

                        <div class="col-span-2 pt-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input 
                                    v-model="form.is_main" 
                                    type="checkbox" 
                                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                <span class="text-xs font-bold text-slate-700">تعيين كمستودع رئيسي للمنشأة</span>
                            </label>
                        </div>
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
                            {{ editingWarehouse ? 'حفظ التعديلات' : 'إضافة المستودع' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

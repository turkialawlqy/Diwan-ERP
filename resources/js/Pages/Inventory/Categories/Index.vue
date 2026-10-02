<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import {
    HomeIcon,
    ChevronRightIcon,
    ArrowPathIcon,
    PlusIcon,
    TagIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    categories: Array,
});

const form = useForm({
    name_ar: '',
    name_en: '',
    code: '',
    color: '#4F46E5',
});

const submit = () => {
    form.post(route('inventory.categories.store'), {
        onSuccess: () => form.reset(),
    });
};

const deleteCategory = (id) => {
    if (confirm('هل ترغب في حذف هذا التصنيف؟')) {
        form.delete(route('inventory.categories.destroy', id));
    }
};
</script>

<template>
    <AppLayout>
        <Head title="تصنيفات المنتجات" />

        <div class="space-y-5 max-w-6xl mx-auto">
            <!-- Header Ribbon -->
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button 
                        @click="router.reload()"
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
                        <Link :href="route('inventory.products.index')" class="text-slate-500 hover:text-indigo-600 transition-colors">المخزون</Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">التصنيفات</span>
                    </nav>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Add Category Form (Right) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
                        <TagIcon class="w-4 h-4 text-indigo-600" />
                        <span>إضافة تصنيف جديد</span>
                    </h3>

                    <form @submit.prevent="submit" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">اسم التصنيف (عربي) <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name_ar" 
                                type="text" 
                                required 
                                placeholder="مثال: إلكترونيات..." 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">اسم التصنيف (إنجليزي)</label>
                            <input 
                                v-model="form.name_en" 
                                type="text" 
                                placeholder="Electronics..." 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">كود التصنيف</label>
                            <input 
                                v-model="form.code" 
                                type="text" 
                                placeholder="ELEC" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 font-mono"
                            />
                        </div>

                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-xs disabled:opacity-50"
                        >
                            إضافة التصنيف
                        </button>
                    </form>
                </div>

                <!-- Categories List (Left 2 columns) -->
                <div class="md:col-span-2 bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3">
                        التصنيفات الحالية ({{ categories.length }})
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div 
                            v-for="cat in categories" 
                            :key="cat.id"
                            class="p-4 rounded-xl border border-slate-100 bg-slate-50/50 hover:bg-indigo-50/30 transition-colors flex items-center justify-between"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-indigo-100/60 text-indigo-700 font-bold flex items-center justify-center text-xs">
                                    {{ cat.name_ar.substring(0, 1) }}
                                </div>
                                <div>
                                    <h4 class="font-bold text-xs text-slate-800">{{ cat.name_ar }}</h4>
                                    <p class="text-2xs text-slate-400 mt-0.5">{{ cat.products_count }} منتج مرتبط</p>
                                </div>
                            </div>
                            <button 
                                @click="deleteCategory(cat.id)" 
                                class="p-1.5 text-slate-300 hover:text-rose-600 transition-colors"
                                title="حذف"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

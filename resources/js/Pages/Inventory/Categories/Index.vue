<script setup>
import { useI18n } from 'vue-i18n';
import ImportButton from '@/Components/UI/ImportButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
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
    PencilSquareIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    categories: Array,
});

const form = useForm({
    name_ar: '',
    name_en: '',
    code: '',
    color: '#4F46E5',
});

// The side form adds a category, or edits the one picked from the list.
const editingId = ref(null);
const editCategory = (cat) => {
    editingId.value = cat.id;
    Object.assign(form, { name_ar: cat.name_ar, name_en: cat.name_en || '', code: cat.code || '', color: cat.color || '#4F46E5' });
};
const cancelEdit = () => { editingId.value = null; form.reset(); };
const submit = () => {
    const done = { onSuccess: () => cancelEdit() };
    if (editingId.value) form.put(route('inventory.categories.update', editingId.value), done);
    else form.post(route('inventory.categories.store'), done);
};

const deleteCategory = (id) => {
    if (confirm(t('i_categories_index.confirm_delete'))) {
        form.delete(route('inventory.categories.destroy', id));
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_categories_index.product_categories')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('i_categories_index.categories')" :crumbs="[{ label: 'التصنيفات' }]">
<template #actions>
<button 
                        @click="router.reload()"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        :title="$t('i_categories_index.refresh_data')"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </button>
                <ImportButton v-if="$can('inventory.categories.create')" type="categories" :title="$t('i_categories_index.import_categories_from_excel')" />
</template>
</PageHeader>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Add Category Form (Right) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
                        <TagIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ editingId ? $t('i_categories_index.edit') : $t('i_categories_index.new') }}</span>
                    </h3>

                    <form @submit.prevent="submit" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('i_categories_index.category_name_arabic') }} <span class="text-rose-500">*</span></label>
                            <input 
                                v-model="form.name_ar" 
                                type="text" 
                                required 
                                :placeholder="$t('i_categories_index.example_electronics')" 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('i_categories_index.category_name_english') }}</label>
                            <input 
                                v-model="form.name_en" 
                                type="text" 
                                placeholder="Electronics..." 
                                class="w-full text-xs rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500"
                            />
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">{{ $t('i_categories_index.category_code') }}</label>
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
                            {{ editingId ? $t('i_categories_index.save_edit') : $t('i_categories_index.add') }}
                        </button>
                        <button v-if="editingId" type="button" @click="cancelEdit" class="w-full py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs">{{ $t('i_categories_index.cancel_editing') }}</button>
                    </form>
                </div>

                <!-- Categories List (Left 2 columns) -->
                <div class="md:col-span-2 bg-white rounded-2xl border border-slate-200/80 p-6 shadow-2xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-800 border-b border-slate-100 pb-3">
                        {{ $t('i_categories_index.current', { n: categories.length }) }}
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
                                    <p class="text-2xs text-slate-400 mt-0.5">{{ $t('i_categories_index.linked', { n: cat.products_count }) }}</p>
                                </div>
                            </div>
                            <span class="flex items-center">
                            <button v-if="$can('inventory.categories.edit')" @click="editCategory(cat)" class="p-1.5 text-slate-300 hover:text-indigo-600 transition-colors" :title="$t('i_categories_index.edit')" data-row-edit>
                                <PencilSquareIcon class="w-4 h-4" />
                            </button>
                            <button 
                                @click="deleteCategory(cat.id)" 
                                class="p-1.5 text-slate-300 hover:text-rose-600 transition-colors"
                                :title="$t('i_categories_index.delete')"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

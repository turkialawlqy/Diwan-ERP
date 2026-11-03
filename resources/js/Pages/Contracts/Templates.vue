<script setup>
import RowActions from '@/Components/UI/RowActions.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ templates: Array, kinds: Object });
</script>

<template>
    <AppLayout>
        <Head title="نماذج العقود" />
        <PageHeader title="نماذج العقود" subtitle="نصوص جاهزة تُستعمل عند إنشاء العقد" :crumbs="[{ label: 'العقود', href: route('contracts.index') }, { label: 'النماذج' }]">
            <template #actions>
                <Link v-if="$can('contracts.templates.create')" :href="route('contracts.templates.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-template><PlusIcon class="w-4 h-4" /> نموذج جديد</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">النموذج</th><th class="px-4 py-2 text-start">النوع</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" class="border-t border-slate-100" :data-template="t.name">
                        <td class="px-4 py-2 font-bold">{{ t.name }}</td><td class="px-4 py-2">{{ kinds[t.kind] }}</td>
                        <td class="px-4 py-2"><span :class="t.is_active ? 'text-emerald-700' : 'text-slate-400'" class="font-bold">{{ t.is_active ? 'نشط' : 'معطّل' }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :edit="$can('contracts.templates.edit') ? route('contracts.templates.edit', t.id) : null" :remove="$can('contracts.templates.delete') ? route('contracts.templates.destroy', t.id) : null" :confirm="`حذف النموذج ${t.name}؟`" /></td>
                    </tr>
                    <tr v-if="!templates.length"><td colspan="4" class="py-10 text-center text-slate-400">لا توجد نماذج بعد.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

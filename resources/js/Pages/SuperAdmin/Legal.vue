<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';

const props = defineProps({ pages: Array });

// One form per legal page (Markdown body).
const forms = Object.fromEntries(props.pages.map((p) => [p.slug, useForm({ title: p.title, body: p.body })]));
const save = (slug) => forms[slug].put(route('super-admin.legal.update', slug), { preserveScroll: true });
</script>

<template>
    <SuperAdminLayout>
        <Head title="الشروط والخصوصية" />
        <div class="space-y-6 text-xs">
            <p class="text-slate-500">تظهر هاتان الصفحتان للعملاء ويوافقون عليهما عند التسجيل. النص الحالي نموذج أولي: راجعه قانونياً. التنسيق بصيغة Markdown (العناوين بـ #، والقوائم بـ -).</p>
            <section v-for="p in pages" :key="p.slug" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <input v-model="forms[p.slug].title" class="flex-1 rounded-xl border-slate-200 text-sm font-bold" />
                    <a :href="route('legal.show', p.slug)" target="_blank" class="text-indigo-600 font-bold">معاينة</a>
                </div>
                <textarea v-model="forms[p.slug].body" rows="18" class="w-full rounded-xl border-slate-200 text-xs font-mono leading-6" dir="rtl"></textarea>
                <p v-if="forms[p.slug].errors.body" class="text-rose-600">{{ forms[p.slug].errors.body }}</p>
                <div class="flex items-center justify-between">
                    <span class="text-slate-400">{{ p.updated_at ? 'آخر تعديل: ' + p.updated_at.substring(0, 10) : 'النص الافتراضي' }}</span>
                    <button @click="save(p.slug)" :disabled="forms[p.slug].processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>

<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { date } from '@/utils/format';

const props = defineProps({ pages: Array });

// One form per legal page (Markdown body).
const forms = Object.fromEntries(props.pages.map((p) => [p.slug, useForm({ title: p.title, body: p.body })]));
const save = (slug) => forms[slug].put(route('super-admin.legal.update', slug), { preserveScroll: true });
</script>

<template>
    <SuperAdminLayout>
        <Head :title="$t('z_superadmin_legal.terms_and_privacy')" />
        <div class="space-y-6 text-xs">
            <p class="text-slate-500">{{ $t('z_superadmin_legal.these_two_pages_are_shown_to_cus') }}</p>
            <section v-for="p in pages" :key="p.slug" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <input v-model="forms[p.slug].title" class="flex-1 rounded-xl border-slate-200 text-sm font-bold" />
                    <a :href="route('legal.show', p.slug)" target="_blank" class="text-indigo-600 font-bold">{{ $t('z_superadmin_legal.preview') }}</a>
                </div>
                <textarea v-model="forms[p.slug].body" rows="18" class="w-full rounded-xl border-slate-200 text-xs font-mono leading-6" dir="rtl"></textarea>
                <p v-if="forms[p.slug].errors.body" class="text-rose-600">{{ forms[p.slug].errors.body }}</p>
                <div class="flex items-center justify-between">
                    <span class="text-slate-400">{{ p.updated_at ? $t('z_superadmin_legal.last_edited') + date(p.updated_at) : $t('z_superadmin_legal.default_text') }}</span>
                    <button @click="save(p.slug)" :disabled="forms[p.slug].processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('z_superadmin_legal.save') }}</button>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>

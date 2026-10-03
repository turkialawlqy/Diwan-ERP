<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ types: Array });
const forms = Object.fromEntries(props.types.map((t) => [t.key, useForm({ file: null })]));
const formFor = (key) => forms[key];
</script>

<template>
    <AppLayout>
        <Head title="استيراد وتصدير" />
        <PageHeader title="استيراد وتصدير البيانات" subtitle="CSV أو Excel؛ يُتحقق من كل الصفوف قبل الحفظ، والسجلات الموجودة (بالرمز) تُحدّث" :crumbs="[{ label: 'الإعدادات' }, { label: 'استيراد وتصدير' }]" />
        <div class="grid md:grid-cols-2 gap-4 text-xs">
            <div v-for="t in types" :key="t.key" class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700">{{ t.label }}</h3>
                <p class="text-slate-400 font-mono mt-1" dir="ltr">{{ t.columns.join(', ') }}</p>
                <div class="flex flex-wrap gap-2 mt-3">
                    <a v-if="t.can_export" :href="route('data-transfer.export', t.key)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">تصدير CSV</a>
                    <a v-if="t.can_import" :href="route('data-transfer.template', t.key)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">قالب الاستيراد</a>
                </div>
                <div v-if="t.can_import" class="flex items-center gap-2 mt-3">
                    <input type="file" accept=".csv,.txt,.xlsx,.xls" @input="formFor(t.key).file = $event.target.files[0]" class="text-xs" />
                    <button @click="formFor(t.key).post(route('data-transfer.import', t.key), { forceFormData: true, preserveScroll: true })" :disabled="!formFor(t.key).file || formFor(t.key).processing" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-40">استيراد</button>
                </div>
                <ul v-if="formFor(t.key).errors.file" class="mt-2 text-rose-600 list-disc ps-4"><li v-for="(e, i) in [].concat(formFor(t.key).errors.file)" :key="i">{{ e }}</li></ul>
            </div>
        </div>
    </AppLayout>
</template>

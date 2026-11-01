<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ modules: Array });
const form = useForm({ enabled: props.modules.filter((m) => m.enabled).map((m) => m.key) });
useUnsavedGuard(form);
const toggle = (key) => {
    form.enabled = form.enabled.includes(key) ? form.enabled.filter((k) => k !== key) : [...form.enabled, key];
};
const save = () => form.put(route('settings.modules.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
</script>

<template>
    <AppLayout>
        <Head title="الوحدات" />
        <PageHeader title="تفعيل وتعطيل الوحدات" subtitle="الوحدة المعطّلة تختفي من القوائم والبحث وتُمنع صفحاتها؛ بياناتها تبقى وتعود عند إعادة التفعيل" :crumbs="[{ label: 'الإعدادات' }, { label: 'الوحدات' }]">
            <template #actions>
                <button type="button" @click="save" :disabled="form.processing || !form.isDirty" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50" data-save-modules>حفظ</button>
            </template>
        </PageHeader>
        <form @submit.prevent="save" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label v-for="m in modules" :key="m.key" class="flex items-start gap-3 bg-white border rounded-2xl p-4 cursor-pointer transition"
                :class="form.enabled.includes(m.key) ? 'border-indigo-300 ring-1 ring-indigo-100' : 'border-slate-200 opacity-80'" :data-module="m.key">
                <span class="relative inline-flex mt-0.5 flex-shrink-0">
                    <input type="checkbox" class="sr-only peer" :checked="form.enabled.includes(m.key)" @change="toggle(m.key)" />
                    <span class="w-10 h-6 rounded-full bg-slate-300 peer-checked:bg-indigo-600 transition"></span>
                    <span class="absolute top-1 start-1 w-4 h-4 rounded-full bg-white shadow transition peer-checked:translate-x-[-1rem] ltr:peer-checked:translate-x-4"></span>
                </span>
                <span class="text-xs">
                    <b class="block text-slate-900 text-sm">{{ m.label.split(' (')[0] }}</b>
                    <span class="text-slate-500">{{ m.label.includes('(') ? m.label.slice(m.label.indexOf('(') + 1, -1) : '' }}</span>
                    <span v-if="!m.in_plan" class="block mt-1 text-amber-600 font-bold">غير مشمولة في باقة الاشتراك الحالية.</span>
                    <span class="block mt-1 font-bold" :class="form.enabled.includes(m.key) ? 'text-emerald-600' : 'text-slate-400'">{{ form.enabled.includes(m.key) ? 'مفعّلة' : 'معطّلة' }}</span>
                </span>
            </label>
        </form>
    </AppLayout>
</template>

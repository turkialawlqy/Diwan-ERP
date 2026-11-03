<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ sla: Array, categories: Array });
const form = useForm({ policies: props.sla.map((p) => ({ priority: p.priority, label: p.label, first_response_minutes: p.first_response_minutes, resolution_minutes: p.resolution_minutes })) });
useUnsavedGuard(form);
const save = () => form.put(route('support.settings.sla'), { preserveScroll: true, onSuccess: () => form.defaults() });
const hours = (m) => (Number(m) >= 60 ? `${(Number(m) / 60).toFixed(Number(m) % 60 ? 1 : 0)} ساعة` : `${m} دقيقة`);

const cat = useForm({ name: '' });
const addCategory = () => cat.post(route('support.settings.categories.store'), { preserveScroll: true, onSuccess: () => cat.reset() });
const toggle = (c) => router.post(route('support.settings.categories.toggle', c.id), {}, { preserveScroll: true });
const remove = (c) => { if (window.confirm(`حذف التصنيف ${c.name}؟`)) router.delete(route('support.settings.categories.destroy', c.id), { preserveScroll: true }); };
const num = 'w-28 rounded-lg border-slate-200 text-xs font-mono';
</script>

<template>
    <AppLayout>
        <Head title="إعدادات الدعم" />
        <PageHeader title="إعدادات الدعم" subtitle="مهل الخدمة (SLA) وتصنيفات التذاكر" :crumbs="[{ label: 'خدمة العملاء' }, { label: 'الإعدادات' }]">
            <template #actions>
                <button v-if="$can('support.settings.edit')" type="button" @click="save" :disabled="form.processing || !form.isDirty" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50" data-save-sla>حفظ المهل</button>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-5">
            <h2 class="px-4 py-3 text-xs font-bold text-slate-800 border-b border-slate-100">مهل الخدمة حسب الأولوية (بالدقائق)</h2>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الأولوية</th><th class="px-4 py-2 text-start">الرد الأول</th><th class="px-4 py-2 text-start">الحل</th></tr></thead>
                <tbody>
                    <tr v-for="(p, i) in form.policies" :key="p.priority" class="border-t border-slate-100" :data-sla-row="p.priority">
                        <td class="px-4 py-2 font-bold">{{ p.label }}</td>
                        <td class="px-4 py-2"><input v-model="p.first_response_minutes" type="text" inputmode="decimal" data-num :class="num" :disabled="!$can('support.settings.edit')" /> <span class="text-slate-400">≈ {{ hours(p.first_response_minutes) }}</span><p v-if="form.errors[`policies.${i}.first_response_minutes`]" class="text-rose-600">{{ form.errors[`policies.${i}.first_response_minutes`] }}</p></td>
                        <td class="px-4 py-2"><input v-model="p.resolution_minutes" type="text" inputmode="decimal" data-num :class="num" :disabled="!$can('support.settings.edit')" /> <span class="text-slate-400">≈ {{ hours(p.resolution_minutes) }}</span><p v-if="form.errors[`policies.${i}.resolution_minutes`]" class="text-rose-600">{{ form.errors[`policies.${i}.resolution_minutes`] }}</p></td>
                    </tr>
                </tbody>
            </table>
            <p class="px-4 py-3 text-3xs text-slate-500">تُحسب المهلة من لحظة فتح التذكرة على مدار الساعة. أول رد علني للعميل يوقف مهلة الرد، والحل يوقف مهلة الحل.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4">
            <h2 class="text-xs font-bold text-slate-800 mb-3">تصنيفات التذاكر</h2>
            <form v-if="$can('support.settings.edit')" @submit.prevent="addCategory" class="flex gap-2 mb-3 text-xs">
                <input v-model="cat.name" required maxlength="255" placeholder="تصنيف جديد، مثل: فواتير، أعطال، استفسار" class="flex-1 rounded-xl border-slate-200 text-xs" data-category-name />
                <button type="submit" :disabled="cat.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-add-category>إضافة</button>
            </form>
            <ul class="divide-y divide-slate-100 text-xs">
                <li v-for="c in categories" :key="c.id" class="py-2 flex items-center justify-between" :data-category="c.name">
                    <span :class="c.is_active ? 'font-bold' : 'text-slate-400 line-through'">{{ c.name }} <span class="text-slate-400 font-normal">({{ c.tickets_count }} تذكرة)</span></span>
                    <span v-if="$can('support.settings.edit')" class="flex items-center gap-2">
                        <button type="button" class="text-indigo-600 font-bold" @click="toggle(c)">{{ c.is_active ? 'تعطيل' : 'تفعيل' }}</button>
                        <button type="button" class="p-1 text-slate-400 hover:text-rose-600" @click="remove(c)" title="حذف"><TrashIcon class="w-4 h-4" /></button>
                    </span>
                </li>
                <li v-if="!categories.length" class="py-6 text-center text-slate-400">لا توجد تصنيفات بعد.</li>
            </ul>
        </div>
    </AppLayout>
</template>

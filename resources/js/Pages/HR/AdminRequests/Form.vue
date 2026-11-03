<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ adminRequest: Object, employees: Array });
const r = props.adminRequest;
const split = (v) => (v ? String(v).replace('T', ' ').slice(0, 16).split(' ') : ['', '']);
const [d, t] = split(r?.attend_at);
const form = useForm({ title: r?.title || '', body: r?.body || '', location: r?.location || '', attend_date: d, attend_time: t || '09:00', employees: r?.employee_ids || [], pick: null, send: false });
useUnsavedGuard(form);
const byId = computed(() => Object.fromEntries(props.employees.map((e) => [e.id, e])));
const options = computed(() => props.employees.filter((e) => !form.employees.includes(e.id)));
const add = (id) => { if (id && !form.employees.includes(id)) form.employees.push(id); form.pick = null; };
const remove = (id) => { form.employees = form.employees.filter((x) => x !== id); };
const submit = (send) => {
    form.transform((x) => ({ title: x.title, body: x.body, location: x.location, employees: x.employees, send, attend_at: x.attend_date ? `${x.attend_date} ${x.attend_time || '00:00'}` : null }));
    if (r) form.put(route('hr.admin-requests.update', r.id));
    else form.post(route('hr.admin-requests.store'));
};
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="r ? `تعديل ${r.number}` : 'طلب إداري جديد'" />
        <PageHeader :title="r ? `تعديل الطلب ${r.number}` : 'طلب إداري جديد'" :crumbs="[{ label: 'الطلبات الإدارية', href: route('hr.admin-requests.index') }, { label: r ? r.number : 'جديد' }]" />
        <form @submit.prevent="submit(false)" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="عنوان الطلب *" :error="form.errors.title" class="md:col-span-3"><input v-model="form.title" required :class="input" data-title placeholder="مثال: حضور اجتماع لجنة التحقيق" /></Field>
            <Field label="تاريخ الحضور" :error="form.errors.attend_at"><div class="flex gap-2"><DatePicker v-model="form.attend_date" /><input v-model="form.attend_time" type="time" :class="input" class="w-28" dir="ltr" /></div></Field>
            <Field label="المكان" :error="form.errors.location" class="md:col-span-2"><input v-model="form.location" :class="input" /></Field>
            <Field label="التفاصيل" :error="form.errors.body" class="md:col-span-3"><textarea v-model="form.body" rows="3" :class="input"></textarea></Field>

            <div class="md:col-span-3">
                <div class="font-bold text-slate-700 mb-2">الموظفون المطلوب حضورهم *</div>
                <div class="max-w-md mb-3"><SelectCreate :model-value="form.pick" @update:model-value="add" :options="options" entity="employee" :label="(e) => `${e.name}${e.department ? ' — ' + e.department : ''}`" placeholder="ابحث عن موظف لإضافته" data-employee-picker /></div>
                <p v-if="form.errors.employees" class="text-rose-600 mb-2">{{ form.errors.employees }}</p>
                <table class="w-full border border-slate-100 rounded-xl overflow-hidden">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الموظف</th><th class="px-3 py-2 text-start">الإدارة</th><th class="w-10"></th></tr></thead>
                    <tbody>
                        <tr v-for="id in form.employees" :key="id" class="border-t border-slate-100" data-employee-line>
                            <td class="px-3 py-2 font-bold">{{ byId[id]?.name }} <span class="font-mono font-normal text-slate-400">{{ byId[id]?.employee_number }}</span></td>
                            <td class="px-3 py-2">{{ byId[id]?.department || '—' }}</td>
                            <td class="px-3 py-2"><button type="button" class="text-slate-400 hover:text-rose-600" @click="remove(id)"><TrashIcon class="w-4 h-4" /></button></td>
                        </tr>
                        <tr v-if="!form.employees.length"><td colspan="3" class="py-6 text-center text-slate-400">لم يُضف موظف بعد.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="md:col-span-3 flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-700" data-save-draft>حفظ كمسودة</button>
                <button type="button" :disabled="form.processing" @click="submit(true)" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-send>حفظ وإرسال</button>
            </div>
        </form>
    </AppLayout>
</template>

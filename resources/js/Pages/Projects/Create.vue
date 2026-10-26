<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import BoqEditor from '@/Components/Projects/BoqEditor.vue';
import BranchSelect from '@/Components/BranchSelect.vue';

const props = defineProps({ managers: Array, warehouses: Array, suggestedCode: String, branches: Array, defaultBranchId: Number });
const form = useForm({ customer_id: null, branch_id: props.defaultBranchId || props.branches?.[0]?.id || '', name: '', contract_number: '', manager_id: '', warehouse_id: '', start_date: '', end_date: '', contract_value: 0, advance_percent: 0, retention_percent: 5, location: '', description: '', boq: [] });
const save = () => {
    form.clearErrors();
    if (!form.name) form.setError('name', 'أدخل اسم المشروع.');
    if (!form.customer_id) form.setError('customer_id', 'اختر العميل (المالك) من نتائج البحث.');
    if (form.hasErrors) return;
    form.post(route('projects.store'), { preserveScroll: true });
};
</script>

<template>
    <AppLayout own-errors>
        <Head title="مشروع جديد" />
        <PageHeader title="مشروع / عقد جديد" :subtitle="`الرمز المتوقع: ${suggestedCode}`" :crumbs="[{ label: 'المقاولات' }, { label: 'المشاريع', href: route('projects.index') }, { label: 'جديد' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid md:grid-cols-4 gap-3">
                <Field label="اسم المشروع" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="العميل (المالك)" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" /></Field>
                <BranchSelect v-if="branches?.length > 1" v-model="form.branch_id" :branches="branches" :error="form.errors.branch_id" />
                <Field label="رقم العقد"><input v-model="form.contract_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مدير المشروع"><select v-model="form.manager_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option></select></Field>
                <Field label="مستودع الموقع"><select v-model="form.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                <Field label="الموقع"><input v-model="form.location" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تاريخ البدء"><input v-model="form.start_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تاريخ الانتهاء" :error="form.errors.end_date"><input v-model="form.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="نسبة استرداد الدفعة المقدمة %" hint="تُخصم من كل مستخلص"><input v-model="form.advance_percent" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="نسبة المحتجزات %"><input v-model="form.retention_percent" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field v-if="!form.boq.length" label="قيمة العقد (بدون جدول كميات)"><input v-model="form.contract_value" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <div>
                <h3 class="text-xs font-black text-slate-700 mb-2">جدول الكميات (BOQ)</h3>
                <BoqEditor v-model="form.boq" />
            </div>
            <div v-if="form.hasErrors" class="rounded-xl bg-rose-50 border border-rose-200 p-3 space-y-1"><p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-700">{{ msg }}</p></div>
            <div class="flex justify-end"><button @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">حفظ المشروع</button></div>
        </div>
    </AppLayout>
</template>

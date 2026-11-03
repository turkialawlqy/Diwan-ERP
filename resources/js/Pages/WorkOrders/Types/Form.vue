<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ type: Object, products: Array, defaultTax: Number });
const t = props.type;
const form = useForm({ name: t?.name || '', hourly_rate: t ? Number(t.hourly_rate) : 0, tax_rate: t ? Number(t.tax_rate) : props.defaultTax, product_id: t?.product_id || '', min_billable_minutes: t?.min_billable_minutes || 0, is_active: t ? !!t.is_active : true });
useUnsavedGuard(form);
const submit = () => (t ? form.put(route('work-orders.types.update', t.id)) : form.post(route('work-orders.types.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="t ? `تعديل ${t.name}` : 'نوع عمل جديد'" />
        <PageHeader :title="t ? `تعديل: ${t.name}` : 'نوع عمل جديد'" :crumbs="[{ label: 'أوامر العمل', href: route('work-orders.index') }, { label: 'الأنواع', href: route('work-orders.types.index') }, { label: t ? t.name : 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="الاسم *" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" data-type-name /></Field>
            <Field label="سعر الساعة للعميل *" :error="form.errors.hourly_rate"><input v-model.number="form.hourly_rate" type="text" inputmode="decimal" data-num :class="input" data-rate /></Field>
            <Field label="نسبة الضريبة %" :error="form.errors.tax_rate"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="أقل مدة تُفوتَر (دقيقة)" :error="form.errors.min_billable_minutes" hint="مثل 60: أي زيارة أقصر تُفوتَر ساعة"><input v-model.number="form.min_billable_minutes" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="بند الإيراد (اختياري)" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" :label="(p) => p.name_ar" placeholder="بدون" /></Field>
            <label class="flex items-center gap-2 pt-5"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" /> نشط</label>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-type>حفظ</button><Link :href="route('work-orders.types.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

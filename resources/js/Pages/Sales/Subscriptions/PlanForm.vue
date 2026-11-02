<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ plan: Object, cycles: Object, products: Array, defaultTax: Number });
const editing = props.plan?.id;
const form = useForm({
    name: props.plan?.name || '',
    description: props.plan?.description || '',
    price: props.plan?.price ?? '',
    tax_rate: props.plan?.tax_rate ?? props.defaultTax ?? 15,
    cycle: props.plan?.cycle || 'monthly',
    interval: props.plan?.interval || 1,
    trial_days: props.plan?.trial_days || 0,
    product_id: props.plan?.product_id || '',
    is_active: props.plan ? !!props.plan.is_active : true,
});
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('sales.service-plans.update', editing)) : form.post(route('sales.service-plans.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? 'تعديل خطة' : 'خطة اشتراك جديدة'" />
        <PageHeader :title="editing ? 'تعديل خطة' : 'خطة اشتراك جديدة'" :crumbs="[{ label: 'المبيعات' }, { label: 'خطط الاشتراكات', href: route('sales.service-plans.index') }, { label: editing ? 'تعديل' : 'جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field label="اسم الخطة *" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" /></Field>
            <Field label="السعر لكل فترة (قبل الضريبة) *" :error="form.errors.price"><input v-model="form.price" required type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="نسبة الضريبة %" :error="form.errors.tax_rate"><input v-model="form.tax_rate" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="دورة الفوترة *" :error="form.errors.cycle">
                <select v-model="form.cycle" :class="input"><option v-for="(label, key) in cycles" :key="key" :value="key">{{ label }}</option></select>
            </Field>
            <Field label="كل كم دورة" :error="form.errors.interval" hint="1 = كل دورة، 2 = كل دورتين..."><input v-model="form.interval" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="أيام التجربة المجانية" :error="form.errors.trial_days" hint="لا تُصدر فاتورة قبل انتهائها"><input v-model="form.trial_days" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="صنف الخدمة في الفاتورة (اختياري)" :error="form.errors.product_id" hint="صنف خدمي من المخزون يظهر في سطر الفاتورة.">
                <SelectCreate v-model="form.product_id" :options="products" entity="product" :label="(p) => p.name_ar" placeholder="بدون صنف" />
            </Field>
            <Field label="الوصف" :error="form.errors.description" class="md:col-span-2"><input v-model="form.description" maxlength="2000" :class="input" /></Field>
            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.is_active" class="rounded" /> الخطة نشطة (تُباع لاشتراكات جديدة)</label>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-plan>{{ editing ? 'حفظ' : 'إضافة' }}</button>
                <Link :href="route('sales.service-plans.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>

<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ products: Array, plans: Array });
const form = useForm({ product_id: '', qc_plan_id: '', inspected_quantity: '', notes: '' });
useUnsavedGuard(form);
const plans = computed(() => props.plans.filter((p) => !p.product_id || p.product_id === form.product_id));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head title="فحص جودة جديد" />
        <PageHeader title="فحص جودة جديد" subtitle="فحص يدوي لمشتريات أو مخزون. فحوصات استلام الإنتاج تُفتح تلقائياً." :crumbs="[{ label: 'التصنيع' }, { label: 'فحوصات الجودة', href: route('manufacturing.inspections.index') }, { label: 'جديد' }]" />
        <form @submit.prevent="form.post(route('manufacturing.inspections.store'))" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-3 gap-4">
                <Field label="المنتج *" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" search="lookup.products" entity="product" label="name_ar" placeholder="— اختر —" required /></Field>
                <Field label="خطة الجودة"><select v-model="form.qc_plan_id" :class="input"><option value="">— بلا خطة —</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
                <Field label="الكمية المفحوصة *" :error="form.errors.inspected_quantity"><input v-model.number="form.inspected_quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
            </div>
            <Field label="ملاحظات"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <div class="flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">فتح الفحص</button><Link :href="route('manufacturing.inspections.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

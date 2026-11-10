<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ plan: Object, products: Array });
const editing = !!props.plan?.id;
const p = props.plan;
const form = useForm({
    name: p.name || '', product_id: p.product_id || '', applies_to: p.applies_to || 'production', is_active: p.is_active ?? true,
    checks: p.checks?.length ? p.checks.map((c) => ({ name: c.name, type: c.type, min: c.min ?? '', max: c.max ?? '' })) : [{ name: '', type: 'passfail', min: '', max: '' }],
});
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('manufacturing.plans.update', p.id)) : form.post(route('manufacturing.plans.store')));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? 'تعديل خطة جودة' : 'خطة جودة جديدة'" />
        <PageHeader :title="editing ? 'تعديل خطة جودة' : 'خطة جودة جديدة'" :crumbs="[{ label: 'التصنيع' }, { label: 'خطط الجودة', href: route('manufacturing.plans.index') }, { label: editing ? 'تعديل' : 'جديدة' }]" />
        <form @submit.prevent="submit" class="space-y-5">
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field label="اسم الخطة *" :error="form.errors.name"><input v-model="form.name" :class="input" required /></Field>
                <Field label="المنتج" hint="فارغ = كل المنتجات"><SelectCreate v-model="form.product_id" :options="products" search="lookup.products" entity="product" label="name_ar" placeholder="كل المنتجات" /></Field>
                <Field label="تُطبَّق على"><select v-model="form.applies_to" :class="input"><option value="production">استلام الإنتاج (يحجز المخزون حتى الفحص)</option><option value="purchase">استلام المشتريات (تسجيل فقط)</option></select></Field>
                <label class="inline-flex items-center gap-2 text-xs"><input v-model="form.is_active" type="checkbox" class="rounded" /> نشطة</label>
            </div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
                <div class="flex items-center justify-between mb-3"><h2 class="font-black text-slate-800 text-sm">بنود الفحص</h2><button type="button" @click="form.checks.push({ name: '', type: 'passfail', min: '', max: '' })" class="text-xs font-bold text-indigo-600 inline-flex items-center gap-1"><PlusIcon class="w-4 h-4" /> بند</button></div>
                <p v-if="form.errors.checks" class="text-xs text-rose-600 mb-2">{{ form.errors.checks }}</p>
                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="text-slate-500"><tr><th class="text-start p-2">البند</th><th class="text-start p-2 w-40">النوع</th><th class="text-start p-2 w-28">أدنى</th><th class="text-start p-2 w-28">أعلى</th><th class="w-10"></th></tr></thead>
                    <tbody><tr v-for="(c, i) in form.checks" :key="i" class="border-t border-slate-100">
                        <td class="p-2"><input v-model="c.name" :class="input" placeholder="الطول، اللون، الشكل..." required /></td>
                        <td class="p-2"><select v-model="c.type" :class="input"><option value="passfail">سليم / معيب</option><option value="numeric">قياس رقمي</option></select></td>
                        <td class="p-2"><input v-model.number="c.min" :disabled="c.type !== 'numeric'" type="text" inputmode="decimal" data-num step="any" :class="input" /></td><td class="p-2"><input v-model.number="c.max" :disabled="c.type !== 'numeric'" type="text" inputmode="decimal" data-num step="any" :class="input" /></td>
                        <td class="p-2"><button type="button" :disabled="form.checks.length <= 1" @click="form.checks.splice(i, 1)" class="p-1 text-slate-400 hover:text-rose-600 disabled:opacity-20"><TrashIcon class="w-4 h-4" /></button></td></tr></tbody>
                </table></div>
            </div>
            <div class="flex gap-2"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">حفظ</button><Link :href="route('manufacturing.plans.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

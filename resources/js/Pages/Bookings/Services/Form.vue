<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ service: Object, resources: Array, products: Array, defaultTax: Number });
const s = props.service;
const form = useForm({
    name: s?.name || '', description: s?.description || '', duration_minutes: s?.duration_minutes || 30, buffer_minutes: s?.buffer_minutes || 0,
    price: s ? Number(s.price) : 0, tax_rate: s ? Number(s.tax_rate) : props.defaultTax, product_id: s?.product_id || '', deposit_percent: s?.deposit_percent || 0,
    online_bookable: s ? !!s.online_bookable : true, free_cancel_hours: s?.free_cancel_hours ?? 24, is_active: s ? !!s.is_active : true, resource_ids: s?.resource_ids || [],
});
useUnsavedGuard(form);
const toggle = (id) => { form.resource_ids = form.resource_ids.includes(id) ? form.resource_ids.filter((x) => x !== id) : [...form.resource_ids, id]; };
const submit = () => (s ? form.put(route('bookings.services.update', s.id)) : form.post(route('bookings.services.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="s ? `تعديل ${s.name}` : 'خدمة جديدة'" />
        <PageHeader :title="s ? `تعديل الخدمة: ${s.name}` : 'خدمة حجز جديدة'" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الخدمات', href: route('bookings.services.index') }, { label: s ? s.name : 'جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="اسم الخدمة *" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" data-service-name /></Field>
            <Field label="المدة (دقيقة) *" :error="form.errors.duration_minutes"><input v-model.number="form.duration_minutes" type="text" inputmode="decimal" data-num :class="input" data-duration /></Field>
            <Field label="السعر *" :error="form.errors.price"><input v-model.number="form.price" type="text" inputmode="decimal" data-num :class="input" data-price /></Field>
            <Field label="نسبة الضريبة %" :error="form.errors.tax_rate"><input v-model.number="form.tax_rate" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="فاصل بعد كل موعد (دقيقة)" :error="form.errors.buffer_minutes" hint="للتنظيف أو التجهيز قبل الموعد التالي"><input v-model.number="form.buffer_minutes" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="نسبة العربون المقترحة %" :error="form.errors.deposit_percent"><input v-model.number="form.deposit_percent" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="الإلغاء المجاني قبل الموعد (ساعة)" :error="form.errors.free_cancel_hours" hint="يلتزم به العميل عند الإلغاء من البوابة"><input v-model.number="form.free_cancel_hours" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="بند الإيراد (اختياري)" :error="form.errors.product_id" hint="صنف من نوع خدمة تُسجَّل به الفاتورة"><SelectCreate v-model="form.product_id" :options="products" :label="(p) => p.name_ar" placeholder="بدون" /></Field>
            <Field label="الوصف" :error="form.errors.description" class="md:col-span-3"><textarea v-model="form.description" rows="2" :class="input"></textarea></Field>
            <div class="md:col-span-3">
                <p class="font-bold text-slate-700 mb-2">الموارد التي تقدّم الخدمة</p>
                <div class="flex flex-wrap gap-2">
                    <label v-for="r in resources" :key="r.id" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" :class="form.resource_ids.includes(r.id) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200'">
                        <input type="checkbox" class="rounded border-slate-300" :checked="form.resource_ids.includes(r.id)" @change="toggle(r.id)" /> {{ r.name }}
                    </label>
                    <p v-if="!resources.length" class="text-slate-400">أضف الموارد أولًا من «الموارد».</p>
                </div>
            </div>
            <label class="flex items-center gap-2"><input v-model="form.online_bookable" type="checkbox" class="rounded border-slate-300" /> يحجزها العميل من البوابة</label>
            <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" /> نشطة</label>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-service>حفظ</button><Link :href="route('bookings.services.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

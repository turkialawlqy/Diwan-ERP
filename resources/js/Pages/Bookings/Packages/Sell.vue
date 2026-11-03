<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today, money } from '@/utils/format';

const props = defineProps({ templates: Array, customerId: Number, customer: Object });
const form = useForm({ customer_id: props.customerId || null, visit_package_id: '', paid: false, date: today() });
useUnsavedGuard(form);
const chosen = computed(() => props.templates.find((t) => t.id === form.visit_package_id));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head title="بيع باقة زيارات" />
        <PageHeader title="بيع باقة زيارات" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الباقات', href: route('bookings.packages.index') }, { label: 'بيع' }]" />
        <form @submit.prevent="form.post(route('bookings.customer-packages.store'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field label="العميل *" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" :initial="customer" /></Field>
            <Field label="الباقة *" :error="form.errors.visit_package_id"><select v-model="form.visit_package_id" required :class="input" data-package-select><option value="">اختر الباقة</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }} — {{ t.visits }} زيارة — {{ money(t.price) }}</option></select></Field>
            <Field label="تاريخ البيع" :error="form.errors.date"><DatePicker v-model="form.date" /></Field>
            <label class="flex items-center gap-2 pt-5"><input v-model="form.paid" type="checkbox" class="rounded border-slate-300" data-paid /> مدفوعة نقدًا الآن (وإلا فاتورة آجلة)</label>
            <p v-if="chosen" class="md:col-span-2 text-slate-500">تُصدر فاتورة بقيمة <span class="font-mono font-bold">{{ money(chosen.price) }}</span> + ضريبة {{ chosen.tax_rate }}%<span v-if="chosen.valid_days"> وتنتهي الباقة بعد {{ chosen.valid_days }} يومًا</span>.</p>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-confirm-sell>بيع الباقة</button><Link :href="route('bookings.packages.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

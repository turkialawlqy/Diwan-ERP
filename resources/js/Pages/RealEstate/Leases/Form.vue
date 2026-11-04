<script setup>
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today } from '@/utils/format';

const props = defineProps({ rentalUnits: Array, templates: Array, unitId: Number, cycles: Object });
const form = useForm({ customer_id: null, property_unit_id: props.unitId || '', start_date: today(), end_date: '', rent_amount: 0, cycle: 'monthly', security_deposit: 0, template_id: '', residential: true });
useUnsavedGuard(form);
const unit = computed(() => props.rentalUnits.find((u) => u.id === form.property_unit_id));
watch(() => form.property_unit_id, () => { if (unit.value && !Number(form.rent_amount)) form.rent_amount = unit.value.rent_amount; }, { immediate: true });
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head title="عقد إيجار جديد" />
        <PageHeader title="عقد إيجار جديد" :crumbs="[{ label: 'العقارات', href: route('real-estate.properties.index') }, { label: 'عقود الإيجار', href: route('real-estate.leases.index') }, { label: 'جديد' }]" />
        <form @submit.prevent="form.post(route('real-estate.leases.store'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <Field label="الوحدة (الشاغرة) *" :error="form.errors.property_unit_id"><SelectCreate v-model="form.property_unit_id" required :options="rentalUnits" entity="property_unit" :label="(u) => u.label" placeholder="اختر الوحدة" data-lease-unit /></Field>
            <Field label="المستأجر *" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" /></Field>
            <Field label="بداية العقد *" :error="form.errors.start_date"><DatePicker v-model="form.start_date" required /></Field>
            <Field label="نهاية العقد *" :error="form.errors.end_date"><DatePicker v-model="form.end_date" required /></Field>
            <Field label="دورة الإيجار *" :error="form.errors.cycle"><select v-model="form.cycle" :class="input"><option v-for="(l, k) in cycles" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="قيمة الإيجار لكل دورة *" :error="form.errors.rent_amount"><input v-model.number="form.rent_amount" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-lease-rent /></Field>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 md:col-span-2"><input type="checkbox" v-model="form.residential" data-residential /> إيجار سكني (معفى من ضريبة القيمة المضافة — VATEX-SA-30)؛ ألغِ التحديد للإيجار التجاري</label>
            <Field label="مبلغ التأمين" :error="form.errors.security_deposit"><input v-model.number="form.security_deposit" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field label="نموذج العقد" :error="form.errors.template_id"><SelectCreate v-model="form.template_id" :options="templates" entity="contract_template" :label="(t) => t.name" placeholder="بدون نص جاهز" /></Field>
            <p class="md:col-span-3 text-slate-500">يُنشأ عقد إيجار واشتراك إيجار تُصدر فواتيره تلقائيًا في مواعيدها، وتصبح الوحدة «مؤجَّرة».</p>
            <div class="md:col-span-3 flex gap-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-lease>إبرام العقد</button><Link :href="route('real-estate.leases.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

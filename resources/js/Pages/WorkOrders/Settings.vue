<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ settings: Object, warehouses: Array });
const form = useForm({ ...props.settings, default_warehouse: props.settings.default_warehouse || '' });
useUnsavedGuard(form);
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head title="إعدادات أوامر العمل" />
        <PageHeader title="إعدادات أوامر العمل" :crumbs="[{ label: 'أوامر العمل', href: route('work-orders.index') }, { label: 'الإعدادات' }]" />
        <form @submit.prevent="form.put(route('work-orders.settings.update'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <label class="flex items-center gap-2"><input v-model="form.require_checklist" type="checkbox" class="rounded border-slate-300" /> لا يُنجَز الأمر قبل إكمال قائمة المهام</label>
            <label class="flex items-center gap-2"><input v-model="form.require_signature" type="checkbox" class="rounded border-slate-300" /> توقيع العميل إلزامي للإنجاز</label>
            <Field label="مستودع القطع الافتراضي" :error="form.errors.default_warehouse"><SelectCreate v-model="form.default_warehouse" :options="warehouses" entity="warehouse" :label="(w) => w.name_ar" placeholder="المستودع الرئيسي" /></Field>
            <Field label="إغلاق المؤقت المنسي بعد (ساعة)" :error="form.errors.stale_timer_hours" hint="مؤقت يعمل أطول من ذلك يُغلق تلقائيًا ويُعلَّم"><input v-model.number="form.stale_timer_hours" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <div class="md:col-span-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-settings>حفظ</button></div>
        </form>
    </AppLayout>
</template>

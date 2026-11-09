<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ settings: Object });
const form = useForm({ ...props.settings });
useUnsavedGuard(form);
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head title="إعدادات الحجوزات" />
        <PageHeader title="إعدادات الحجوزات" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الإعدادات' }]" />
        <form @submit.prevent="form.put(route('bookings.settings.update'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field label="خطوة الأوقات (دقيقة)" :error="form.errors.slot_step" hint="الفاصل بين أوقات البدء المعروضة"><input v-model.number="form.slot_step" type="text" inputmode="decimal" data-num :class="input" data-step /></Field>
            <Field label="أقل مهلة قبل الموعد (ساعة)" :error="form.errors.min_notice_hours" hint="يطبَّق على حجز العملاء من البوابة"><input v-model.number="form.min_notice_hours" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="أقصى مدة للحجز المسبق (يوم)" :error="form.errors.max_advance_days"><input v-model.number="form.max_advance_days" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field label="العربون عند عدم الحضور أو الإلغاء المتأخر" :error="form.errors.deposit_on_cancel" hint="يبقى رصيدًا للعميل يُسترد أو يُستخدم لاحقًا، أو يُصادَر إيرادًا. الإلغاء قبل مدة الإلغاء المجاني للخدمة يُبقي العربون رصيدًا دائمًا، وللموظف أن يختار عند الإلغاء"><select v-model="form.deposit_on_cancel" :class="input" data-deposit-on-cancel><option value="credit">يبقى رصيدًا للعميل</option><option value="forfeit">يُصادَر (إيرادات أخرى)</option></select></Field>
            <Field label="ساعات التذكير قبل الموعد" :error="form.errors.reminder_hours" hint="أرقام تفصلها فاصلة، مثل 24,2؛ يُرسل التذكير للعميل بالبريد"><input v-model="form.reminder_hours" :class="input" dir="ltr" /></Field>
            <label class="md:col-span-2 flex items-center gap-2"><input v-model="form.auto_confirm" type="checkbox" class="rounded border-slate-300" /> تأكيد حجوزات البوابة تلقائيًا (وإلا تنتظر تأكيد الموظف)</label>
            <div class="md:col-span-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-settings>حفظ</button></div>
        </form>
    </AppLayout>
</template>

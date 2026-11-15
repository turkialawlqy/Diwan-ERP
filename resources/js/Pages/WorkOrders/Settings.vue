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
        <Head :title="$t('wo2_workorders_settings.work_order_settings')" />
        <PageHeader :title="$t('wo2_workorders_settings.work_order_settings')" :crumbs="[{ label: $t('wo2_workorders_settings.work_orders'), href: route('work-orders.index') }, { label: $t('wo2_workorders_settings.settings') }]" />
        <form @submit.prevent="form.put(route('work-orders.settings.update'))" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <label class="flex items-center gap-2"><input v-model="form.require_checklist" type="checkbox" class="rounded border-slate-300" /> {{ $t('wo2_workorders_settings.an_order_cannot_be_completed_bef') }}</label>
            <label class="flex items-center gap-2"><input v-model="form.require_signature" type="checkbox" class="rounded border-slate-300" /> {{ $t('wo2_workorders_settings.a_customer_signature_is_required') }}</label>
            <Field :label="$t('wo2_workorders_settings.default_parts_warehouse')" :error="form.errors.default_warehouse"><SelectCreate v-model="form.default_warehouse" :options="warehouses" entity="warehouse" :label="(w) => w.name_ar" :placeholder="$t('wo2_workorders_settings.main_warehouse')" /></Field>
            <Field :label="$t('wo2_workorders_settings.close_a_forgotten_timer_after_ho')" :error="form.errors.stale_timer_hours" :hint="$t('wo2_workorders_settings.a_timer_running_longer_than_that')"><input v-model.number="form.stale_timer_hours" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <div class="md:col-span-2 pt-2 border-t border-slate-100"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-settings>{{ $t('wo2_workorders_settings.save') }}</button></div>
        </form>
    </AppLayout>
</template>

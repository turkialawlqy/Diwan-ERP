<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { thisMonth, monthEnd } from '@/utils/format';

const props = defineProps({ stages: Array });
const month = thisMonth();
const form = useForm({
    process_stage_id: props.stages?.[0]?.id || '',
    date_from: `${month}-01`,
    date_to: monthEnd(month),
});
useUnsavedGuard(form);
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_process_periods_create.open_a_cost_period')" />
        <PageHeader :title="$t('mfg_manufacturing_process_periods_create.open_a_cost_period')" :crumbs="[{ label: $t('mfg_manufacturing_process_periods_create.manufacturing') }, { label: $t('mfg_manufacturing_process_periods_create.cost_periods'), href: route('manufacturing.periods.index') }, { label: $t('mfg_manufacturing_process_periods_create.new') }]" />
        <form @submit.prevent="form.post(route('manufacturing.periods.store'))" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-3 gap-4">
                <Field :label="$t('mfg_manufacturing_process_periods_create.stage')" :error="form.errors.process_stage_id || form.errors.stage"><select v-model="form.process_stage_id" :class="input" required><option v-for="s in stages" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option></select></Field>
                <Field :label="$t('mfg_manufacturing_process_periods_create.from')"><DatePicker v-model="form.date_from" :class="input" /></Field>
                <Field :label="$t('mfg_manufacturing_process_periods_create.to')" :error="form.errors.date_to"><DatePicker v-model="form.date_to" :class="input" /></Field>
            </div>
            <p class="text-xs text-slate-500">{{ $t('mfg_manufacturing_process_periods_create.the_period_starts_with_the_wip_l') }}</p>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">{{ $t('mfg_manufacturing_process_periods_create.open_the_period') }}</button>
                <Link :href="route('manufacturing.periods.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('mfg_manufacturing_process_periods_create.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

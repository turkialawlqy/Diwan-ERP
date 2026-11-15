<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today } from '@/utils/format';

/** One equipment's daily preparation, with its preparation cards attached (one file or several). */
const props = defineProps({ preparation: Object, equipment: Array, defaults: Object, suggestedNumber: String });
const editing = props.preparation;

const form = useForm({
    equipment_id: editing?.equipment_id ?? props.defaults?.equipment_id ?? '',
    prep_date: editing?.prep_date ?? props.defaults?.prep_date ?? today(),
    operator: editing?.operator ?? '',
    hours_reading: editing?.hours_reading ?? '',
    km_reading: editing?.km_reading ?? '',
    status: editing?.status ?? 'ready',
    notes: editing?.notes ?? '',
    attachment_ids: [],
});
useUnsavedGuard(form);

const selected = computed(() => props.equipment.find((e) => String(e.id) === String(form.equipment_id)));
const usesHours = computed(() => ['hours', 'both'].includes(selected.value?.meter_type));
const usesKm = computed(() => ['km', 'both'].includes(selected.value?.meter_type));

const submit = () => (editing ? form.put(route('equipment.preparations.update', editing.id)) : form.post(route('equipment.preparations.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('eq_prep.edit_title', { number: editing.number }) : $t('eq_prep.new')" />
        <PageHeader :title="editing ? $t('eq_prep.edit_title', { number: editing.number }) : $t('eq_prep.new')" :subtitle="editing ? '' : $t('eq_prep.expected_number', { n: suggestedNumber })" :crumbs="[{ label: $t('eq2_equipment_preparations_form.equipment'), href: route('equipment.index') }, { label: $t('eq_prep.title'), href: route('equipment.preparations.index') }, { label: editing ? editing.number : $t('eq_prep.new') }]" />

        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <Field :label="$t('eq_prep.equipment')" :error="form.errors.equipment_id" class="md:col-span-2">
                    <SelectCreate v-model="form.equipment_id" :options="equipment" :label="(e) => `${e.code} — ${e.name}`" :allow-empty="false" required :placeholder="$t('eq_prep.pick_equipment')" data-prep-equipment />
                </Field>
                <Field :label="$t('ui.date')" :error="form.errors.prep_date"><DatePicker v-model="form.prep_date" :max="today()" :class="input" /></Field>
                <Field :label="$t('eq_prep.operator')" :error="form.errors.operator"><input v-model="form.operator" :class="input" /></Field>
                <Field v-if="usesHours" :label="$t('eq_prep.hours_reading')" :error="form.errors.hours_reading"><input v-model="form.hours_reading" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
                <Field v-if="usesKm" :label="$t('eq_prep.km_reading')" :error="form.errors.km_reading"><input v-model="form.km_reading" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
                <Field :label="$t('ui.status')" :error="form.errors.status">
                    <div class="flex gap-4 pt-2">
                        <label class="inline-flex items-center gap-2"><input v-model="form.status" type="radio" value="ready" class="text-emerald-600" /> {{ $t('eq_prep.ready_one') }}</label>
                        <label class="inline-flex items-center gap-2"><input v-model="form.status" type="radio" value="needs_attention" class="text-amber-600" /> {{ $t('eq_prep.needs_attention_one') }}</label>
                    </div>
                </Field>
                <Field :label="$t('eq_prep.notes')" :error="form.errors.notes" class="md:col-span-3"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            </div>

            <!-- Preparation cards: one file or several. -->
            <AttachmentsPanel v-if="editing" kind="equipment-preparation" :id="editing.id" />
            <AttachmentsPanel v-else kind="equipment-preparation" v-model="form.attachment_ids" />

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-prep-save>{{ $t('eq_prep.save') }}</button>
                <Link :href="route('equipment.preparations.index', { date: form.prep_date })" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('ui.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

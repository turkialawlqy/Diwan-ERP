<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date as fmtDate, today } from '@/utils/format';
const { t } = useI18n();

/**
 * Daily sheet: the supervisor fills one row per working equipment. Worked
 * hours default to the hour-meter difference; enter them for km-only
 * equipment or when the meter does not reflect billable time.
 */
const props = defineProps({ date: String, rows: Array, recent: Array });

const form = useForm({
    date: props.date,
    rows: props.rows.map((r) => ({
        equipment_id: r.equipment.id,
        hours_reading: r.reading?.hours_reading ?? '',
        km_reading: r.reading?.km_reading ?? '',
        worked_hours: r.reading ? r.reading.worked_hours : '',
        operator: r.reading?.operator ?? '',
    })),
});
const changeDate = (value) => router.get(route('equipment.readings.index'), { date: value }, { preserveState: false });
const save = () => form.post(route('equipment.readings.store'), { preserveScroll: true });
const usesHours = (e) => ['hours', 'both'].includes(e.meter_type);
const usesKm = (e) => ['km', 'both'].includes(e.meter_type);
const err = (i, f) => form.errors[`rows.${i}.${f}`];
const statusLabel = computed(() => ({ rented: t('eq2_equipment_readings.rented'), internal: t('eq2_equipment_readings.on_a_project'), available: t('eq2_equipment_readings.available'), maintenance: t('eq2_equipment_readings.under_maintenance') }));
const where = (e) => e.active_rental ? (e.active_rental.customer?.name || e.active_rental.project?.name || '') : '';
const delta = (i, field, prevKey) => {
    const prev = props.rows[i].previous?.[prevKey];
    const v = form.rows[i][field];
    return v !== '' && v !== null && prev !== undefined && prev !== null ? Number(v) - Number(prev) : null;
};
const filled = computed(() => form.rows.filter((r) => r.hours_reading !== '' || r.km_reading !== '' || r.worked_hours !== '').length);
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq2_equipment_readings.daily_meter_readings')" />
        <PageHeader :title="$t('eq2_equipment_readings.daily_meter_readings')" :subtitle="$t('eq2_equipment_readings.the_meter_reading_updates_mainte')" :crumbs="[{ label: $t('eq2_equipment_readings.equipment_2'), href: route('equipment.index') }, { label: $t('eq2_equipment_readings.readings') }]">
            <template #actions>
                <label class="text-xs font-bold text-slate-600 flex items-center gap-2">{{ $t('eq2_equipment_readings.date') }} <DatePicker :model-value="date" :max="today()" @change="changeDate($event.target.value)" class="rounded-xl border-slate-200 text-xs" /></label>
            </template>
        </PageHeader>

        <p v-if="form.errors.rows" class="mb-3 rounded-xl bg-rose-50 border border-rose-200 p-3 text-xs font-bold text-rose-700">{{ form.errors.rows }}</p>

        <form @submit.prevent="save" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_readings.equipment') }}</th><th class="px-3 py-2 text-start">{{ $t('eq2_equipment_readings.last_reading') }}</th><th class="px-3 py-2 text-start w-36">{{ $t('eq2_equipment_readings.hour_meter') }}</th><th class="px-3 py-2 text-start w-36">{{ $t('eq2_equipment_readings.kilometers') }}</th><th class="px-3 py-2 text-start w-28">{{ $t('eq2_equipment_readings.working_hours') }}</th><th class="px-3 py-2 text-start w-40">{{ $t('eq2_equipment_readings.operator') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="r.equipment.id" class="border-t border-slate-100 align-top">
                        <td class="px-3 py-2">
                            <Link :href="route('equipment.show', r.equipment.id)" class="font-bold text-slate-800 hover:text-indigo-700">{{ r.equipment.code }} — {{ r.equipment.name }}</Link>
                            <div class="text-slate-400">{{ statusLabel[r.equipment.status] }}<template v-if="where(r.equipment)"> · {{ where(r.equipment) }}</template></div>
                        </td>
                        <td class="px-3 py-2 text-slate-500 whitespace-nowrap">
                            <template v-if="r.previous">{{ fmtDate(r.previous.date) }}<div class="font-mono"><span v-if="r.previous.hours_reading !== null">{{ $t('eq2_equipment_readings.n_h', { n0: r.previous.hours_reading }) }}</span> <span v-if="r.previous.km_reading !== null">{{ $t('eq2_equipment_readings.n_km', { n0: Number(r.previous.km_reading).toLocaleString('en-US') }) }}</span></div></template>
                            <span v-else class="font-mono">{{ usesHours(r.equipment) ? $t('eq2_equipment_readings.n_h', { n0: r.equipment.current_hours }) : '' }} {{ usesKm(r.equipment) ? $t('eq2_equipment_readings.n_km', { n0: Number(r.equipment.current_km).toLocaleString('en-US') }) : '' }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="usesHours(r.equipment)">
                                <input v-model.number="form.rows[i].hours_reading" type="text" inputmode="decimal" data-num min="0" step="0.1" :aria-label="$t('eq2_equipment_readings.hour_meter_n', { n0: r.equipment.code })" :class="['w-full rounded-lg text-xs font-mono', err(i, 'hours_reading') ? 'border-rose-400 bg-rose-50' : 'border-slate-200']" />
                                <div v-if="delta(i, 'hours_reading', 'hours_reading') !== null" class="text-[10px] text-slate-400">{{ $t('eq2_equipment_readings.n_h_2', { n0: delta(i, 'hours_reading', 'hours_reading').toFixed(1) }) }}</div>
                                <div v-if="err(i, 'hours_reading')" class="text-[10px] text-rose-600">{{ err(i, 'hours_reading') }}</div>
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="usesKm(r.equipment)">
                                <input v-model.number="form.rows[i].km_reading" type="text" inputmode="decimal" data-num min="0" step="0.1" :aria-label="$t('eq2_equipment_readings.kilometers_n', { n0: r.equipment.code })" :class="['w-full rounded-lg text-xs font-mono', err(i, 'km_reading') ? 'border-rose-400 bg-rose-50' : 'border-slate-200']" />
                                <div v-if="delta(i, 'km_reading', 'km_reading') !== null" class="text-[10px] text-slate-400">{{ $t('eq2_equipment_readings.n_km_2', { n0: delta(i, 'km_reading', 'km_reading').toLocaleString('en-US') }) }}</div>
                                <div v-if="err(i, 'km_reading')" class="text-[10px] text-rose-600">{{ err(i, 'km_reading') }}</div>
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <input v-model.number="form.rows[i].worked_hours" type="text" inputmode="decimal" data-num min="0" max="24" step="0.5" :placeholder="usesHours(r.equipment) ? $t('eq2_equipment_readings.from_the_meter') : ''" :aria-label="$t('eq2_equipment_readings.working_hours_n', { n0: r.equipment.code })" :class="['w-full rounded-lg text-xs font-mono', err(i, 'worked_hours') ? 'border-rose-400 bg-rose-50' : 'border-slate-200']" />
                            <div v-if="err(i, 'worked_hours')" class="text-[10px] text-rose-600">{{ err(i, 'worked_hours') }}</div>
                        </td>
                        <td class="px-3 py-2"><input v-model="form.rows[i].operator" :aria-label="$t('eq2_equipment_readings.operator_n', { n0: r.equipment.code })" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="6" class="px-3 py-10 text-center text-slate-400">{{ $t('eq2_equipment_readings.no_equipment_with_meters_add_equ') }}</td></tr>
                </tbody>
            </table>
            <div v-if="rows.length && $can('equipment.readings.create')" class="flex items-center justify-between gap-3 p-3 border-t border-slate-100 bg-slate-50">
                <span class="text-slate-500">{{ $t('eq2_equipment_readings.n_of_n_equipment_entered_empty_f', { n0: filled, n1: rows.length }) }}</span>
                <button type="submit" :disabled="form.processing" class="px-6 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50">{{ $t('eq2_equipment_readings.save_readings') }}</button>
            </div>
        </form>
    </AppLayout>
</template>

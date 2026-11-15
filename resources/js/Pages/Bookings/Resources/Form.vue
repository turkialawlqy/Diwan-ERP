<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ resource: Object, types: Object, days: Object, services: Array, employees: Array });
const r = props.resource;
const form = useForm({
    name: r?.name || '', type: r?.type || 'staff', employee_id: r?.employee_id || '', capacity: r?.capacity || 1, color: r?.color || '', is_active: r ? !!r.is_active : true, notes: r?.notes || '',
    hours: (r?.hours || []).map((h) => ({ weekday: h.weekday, start_time: h.start_time, end_time: h.end_time })),
    time_offs: (r?.time_offs || []).map((o) => ({ starts_at: String(o.starts_at).slice(0, 16).replace('T', ' '), ends_at: String(o.ends_at).slice(0, 16).replace('T', ' '), reason: o.reason || '' })),
    service_ids: r?.service_ids || [],
});
useUnsavedGuard(form);
const dayOrder = [6, 7, 1, 2, 3, 4, 5];
const addHours = (weekday) => form.hours.push({ weekday, start_time: '09:00', end_time: '17:00' });
const fillWeek = () => { form.hours = [6, 7, 1, 2, 3, 4].map((d) => ({ weekday: d, start_time: '09:00', end_time: '17:00' })); };
const toggleService = (id) => { form.service_ids = form.service_ids.includes(id) ? form.service_ids.filter((x) => x !== id) : [...form.service_ids, id]; };
const submit = () => (r ? form.put(route('bookings.resources.update', r.id)) : form.post(route('bookings.resources.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="r ? $t('bk2_bookings_resources_form.edit_n', { n0: r.name }) : $t('bk2_bookings_resources_form.new_resource')" />
        <PageHeader :title="r ? $t('bk2_bookings_resources_form.edit_resource_n', { n0: r.name }) : $t('bk2_bookings_resources_form.new_booking_resource')" :crumbs="[{ label: $t('bk2_bookings_resources_form.bookings'), href: route('bookings.calendar') }, { label: $t('bk2_bookings_resources_form.resources'), href: route('bookings.resources.index') }, { label: r ? r.name : $t('bk2_bookings_resources_form.new') }]" />
        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <Field :label="$t('bk2_bookings_resources_form.name')" :error="form.errors.name"><input v-model="form.name" required :class="input" data-resource-name /></Field>
                <Field :label="$t('bk2_bookings_resources_form.type')" :error="form.errors.type"><select v-model="form.type" :class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="$t('bk2_bookings_resources_form.concurrent_bookings')" :error="form.errors.capacity" :hint="$t('bk2_bookings_resources_form.1_for_an_employee_or_a_room_more')"><input v-model.number="form.capacity" type="text" inputmode="decimal" data-num :class="input" /></Field>
                <Field v-if="form.type === 'staff'" :label="$t('bk2_bookings_resources_form.linked_employee')"><SelectCreate v-model="form.employee_id" :options="employees" :label="(e) => e.name" :placeholder="$t('bk2_bookings_resources_form.none')" /></Field>
                <label class="flex items-center gap-2 pt-5"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" /> {{ $t('bk2_bookings_resources_form.active') }}</label>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between"><h2 class="font-bold text-slate-800">{{ $t('bk2_bookings_resources_form.weekly_working_hours') }}</h2><button type="button" class="text-indigo-600 font-bold" @click="fillWeek" data-fill-week>{{ $t('bk2_bookings_resources_form.9_5_shift_from_saturday_to_thurs') }}</button></div>
                <p class="text-slate-500">{{ $t('bk2_bookings_resources_form.add_more_than_one_period_in_the') }}</p>
                <div v-for="d in dayOrder" :key="d" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                    <span class="w-20 font-bold">{{ days[d] }}</span>
                    <template v-for="(h, i) in form.hours" :key="i">
                        <span v-if="h.weekday === d" class="inline-flex items-center gap-1" :data-hours="`${d}-${i}`">
                            <input v-model="h.start_time" type="time" class="rounded-lg border-slate-200 text-xs" dir="ltr" /> — <input v-model="h.end_time" type="time" class="rounded-lg border-slate-200 text-xs" dir="ltr" />
                            <button type="button" class="p-1 text-rose-600" @click="form.hours.splice(i, 1)"><TrashIcon class="w-4 h-4" /></button>
                        </span>
                    </template>
                    <button type="button" class="px-2 py-1 rounded-lg border border-slate-200 font-bold text-slate-600" @click="addHours(d)"><PlusIcon class="w-3.5 h-3.5 inline" /> {{ $t('bk2_bookings_resources_form.period') }}</button>
                </div>
                <p v-if="form.errors.hours" class="text-rose-600 font-bold">{{ form.errors.hours }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                <h2 class="font-bold text-slate-800">{{ $t('bk2_bookings_resources_form.time_off_and_blocks') }}</h2>
                <div v-for="(o, i) in form.time_offs" :key="i" class="flex flex-wrap items-center gap-2">
                    <input v-model="o.starts_at" :placeholder="$t('bk2_bookings_resources_form.from_2026_06_15_09_00')" class="rounded-lg border-slate-200 text-xs w-44" dir="ltr" /> — <input v-model="o.ends_at" :placeholder="$t('bk2_bookings_resources_form.to')" class="rounded-lg border-slate-200 text-xs w-44" dir="ltr" />
                    <input v-model="o.reason" :placeholder="$t('bk2_bookings_resources_form.reason')" class="rounded-lg border-slate-200 text-xs w-48" /><button type="button" class="p-1 text-rose-600" @click="form.time_offs.splice(i, 1)"><TrashIcon class="w-4 h-4" /></button>
                </div>
                <button type="button" class="px-2 py-1 rounded-lg border border-slate-200 font-bold text-slate-600" @click="form.time_offs.push({ starts_at: '', ends_at: '', reason: '' })">{{ $t('bk2_bookings_resources_form.time_off') }}</button>
                <p v-if="form.errors.time_offs" class="text-rose-600 font-bold">{{ form.errors.time_offs }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <h2 class="font-bold text-slate-800 mb-2">{{ $t('bk2_bookings_resources_form.services_it_provides') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <label v-for="s in services" :key="s.id" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" :class="form.service_ids.includes(s.id) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200'">
                        <input type="checkbox" class="rounded border-slate-300" :checked="form.service_ids.includes(s.id)" @change="toggleService(s.id)" /> {{ s.name }}
                    </label>
                    <p v-if="!services.length" class="text-slate-400">{{ $t('bk2_bookings_resources_form.no_services_yet_add_them_from_bo') }}</p>
                </div>
            </div>
            <div class="flex gap-2"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-resource>{{ $t('bk2_bookings_resources_form.save') }}</button><Link :href="route('bookings.resources.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('bk2_bookings_resources_form.cancel') }}</Link></div>
        </form>
    </AppLayout>
</template>

<script setup>
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today } from '@/utils/format';

const props = defineProps({ patient: Object, visits: Array, doctors: Array, bookings: Array, unrecorded: Array });
const form = useForm({ visit_date: today(), doctor_id: '', booking_id: '', complaint: '', diagnosis: '', treatment: '', prescription: '', next_visit_date: '', files: [] });
useUnsavedGuard(form);
const pickFiles = (e) => { form.files = Array.from(e.target.files || []); };
const save = () => form.post(route('clinic.visits.store', props.patient.id), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_clinic_patients_show.file_n', { n0: patient.file_number })" />
        <PageHeader :title="`${patient.customer?.name} — ${patient.file_number}`" :crumbs="[{ label: $t('x_clinic_patients_show.clinic') }, { label: $t('x_clinic_patients_show.patient_files'), href: route('clinic.patients.index') }, { label: patient.file_number }]">
            <template #actions><Link v-if="$can('clinic.patients.edit')" :href="route('clinic.patients.edit', patient.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('x_clinic_patients_show.edit_the_file') }}</Link>
                <Link v-if="$can('bookings.bookings.create')" :href="route('bookings.create', { customer: patient.customer_id })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('x_clinic_patients_show.book_an_appointment') }}</Link></template>
        </PageHeader>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="space-y-4" :class="{ 'lg:col-span-3': !visits && !$can('clinic.visits.create') }">
                <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-1.5" data-patient-card>
                    <p><span class="text-slate-500">{{ $t('x_clinic_patients_show.mobile') }}</span> <span class="font-mono" dir="ltr">{{ patient.customer?.phone || '—' }}</span></p>
                    <p><span class="text-slate-500">{{ $t('x_clinic_patients_show.born') }}</span> {{ d(patient.birth_date) }} · {{ patient.gender === 'male' ? $t('x_clinic_patients_show.male') : patient.gender === 'female' ? $t('x_clinic_patients_show.female') : '—' }} · <b class="font-mono">{{ patient.blood_type || '—' }}</b></p>
                    <p v-if="patient.allergies" class="text-rose-700"><b>{{ $t('x_clinic_patients_show.allergy') }}</b> {{ patient.allergies }}</p>
                    <p v-if="patient.chronic_conditions"><b>{{ $t('x_clinic_patients_show.chronic_conditions') }}</b> {{ patient.chronic_conditions }}</p>
                    <p v-if="patient.emergency_contact"><span class="text-slate-500">{{ $t('x_clinic_patients_show.emergency') }}</span> {{ patient.emergency_contact }}</p>
                    <p v-if="patient.notes" class="whitespace-pre-line text-slate-600">{{ patient.notes }}</p>
                </div>
                <div v-if="bookings.length" class="bg-white border border-slate-200 rounded-2xl p-5"><h2 class="font-bold mb-1">{{ $t('x_clinic_patients_show.upcoming_appointments') }}</h2><Link v-for="b in bookings" :key="b.id" :href="route('bookings.show', b.id)" class="block py-0.5 text-indigo-600"><span class="font-mono">{{ String(b.starts_at).slice(0, 16).replace('T', ' ') }}</span> {{ b.service?.name }}</Link></div>
                <div v-if="unrecorded.length" class="bg-amber-50 border border-amber-200 rounded-2xl p-5"><h2 class="font-bold mb-1 text-amber-800">{{ $t('x_clinic_patients_show.completed_appointments_with_no_v') }}</h2>
                    <button v-for="b in unrecorded" :key="b.id" type="button" class="block py-0.5 text-amber-900 underline" @click="form.booking_id = b.id; form.visit_date = String(b.starts_at).slice(0, 10)">{{ String(b.starts_at).slice(0, 10) }} {{ b.service?.name }}</button></div>
            </div>

            <div v-if="visits || $can('clinic.visits.create')" class="lg:col-span-2 space-y-4">
                <form v-if="$can('clinic.visits.create')" @submit.prevent="save" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-3" data-visit-form>
                    <h2 class="font-bold md:col-span-2">{{ $t('x_clinic_patients_show.new_visit') }}</h2>
                    <Field :label="$t('x_clinic_patients_show.date')" :error="form.errors.visit_date"><DatePicker v-model="form.visit_date" /></Field>
                    <Field :label="$t('x_clinic_patients_show.doctor')" :error="form.errors.doctor_id"><SelectCreate v-model="form.doctor_id" :options="doctors" entity="employee" :label="(x) => x.name" placeholder="—" /></Field>
                    <Field :label="$t('x_clinic_patients_show.complaint_2')" :error="form.errors.complaint" class="md:col-span-2"><textarea v-model="form.complaint" rows="2" :class="input" data-complaint></textarea></Field>
                    <Field :label="$t('x_clinic_patients_show.diagnosis_2')" :error="form.errors.diagnosis"><textarea v-model="form.diagnosis" rows="2" :class="input" data-diagnosis></textarea></Field>
                    <Field :label="$t('x_clinic_patients_show.treatment_2')" :error="form.errors.treatment"><textarea v-model="form.treatment" rows="2" :class="input"></textarea></Field>
                    <Field :label="$t('x_clinic_patients_show.prescription_2')" :error="form.errors.prescription"><textarea v-model="form.prescription" rows="2" :class="input"></textarea></Field>
                    <Field :label="$t('x_clinic_patients_show.next_follow_up_date')" :error="form.errors.next_visit_date"><DatePicker v-model="form.next_visit_date" /></Field>
                    <Field :label="$t('x_clinic_patients_show.attachments_images_pdf')" :error="form.errors['files.0']" class="md:col-span-2"><input type="file" multiple accept="image/*,application/pdf" @change="pickFiles" class="text-xs" /></Field>
                    <div class="md:col-span-2"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-visit>{{ $t('x_clinic_patients_show.save_the_visit') }}</button></div>
                </form>

                <div v-if="visits" class="space-y-3" data-visits>
                    <div v-for="v in visits" :key="v.id" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-1" :data-visit="d(v.visit_date)">
                        <p class="font-bold"><span class="font-mono">{{ d(v.visit_date) }}</span><span v-if="v.doctor" class="font-normal text-slate-500"> {{ $t('x_clinic_patients_show.dr_n', { n0: v.doctor.name }) }}</span></p>
                        <p v-if="v.complaint"><b>{{ $t('x_clinic_patients_show.complaint') }}</b> {{ v.complaint }}</p><p v-if="v.diagnosis"><b>{{ $t('x_clinic_patients_show.diagnosis') }}</b> {{ v.diagnosis }}</p><p v-if="v.treatment"><b>{{ $t('x_clinic_patients_show.treatment') }}</b> {{ v.treatment }}</p><p v-if="v.prescription"><b>{{ $t('x_clinic_patients_show.prescription') }}</b> {{ v.prescription }}</p>
                        <p v-if="v.next_visit_date" class="text-sky-700"><b>{{ $t('x_clinic_patients_show.next_follow_up') }}</b> {{ d(v.next_visit_date) }}</p>
                        <p v-if="v.files.length"><a v-for="(f, i) in v.files" :key="i" :href="f.url" target="_blank" class="text-indigo-600 font-bold me-3">{{ f.name }}</a></p>
                    </div>
                    <p v-if="!visits.length" class="text-center text-slate-400 py-8">{{ $t('x_clinic_patients_show.no_visits_recorded') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

const props = defineProps({ date: String, employees: Array, records: Object, summary: Array });
const form = useForm({
    date: props.date,
    rows: props.employees.map((e) => ({ employee_id: e.id, name: e.name, number: e.employee_number, check_in: props.records[e.id]?.check_in?.substring(0, 5) || '', check_out: props.records[e.id]?.check_out?.substring(0, 5) || '', status: props.records[e.id]?.status || '' })),
});
const importForm = useForm({ file: null });

// Bulk entry: tick employees, set one status / in / out, apply to all ticked rows, then save once.
const selected = ref([]);
const allSelected = computed(() => form.rows.length > 0 && selected.value.length === form.rows.length);
const toggleAll = () => { selected.value = allSelected.value ? [] : form.rows.map((r) => r.employee_id); };
const bulk = ref({ status: '', check_in: '', check_out: '' });
const applyBulk = (override = null) => {
    const v = override || bulk.value;
    form.rows.filter((r) => selected.value.includes(r.employee_id)).forEach((r) => {
        if (v.status) r.status = v.status;
        if (v.check_in) r.check_in = v.check_in;
        if (v.check_out) r.check_out = v.check_out;
    });
};
const changeDate = (e) => router.get(route('hr.attendance.index'), { date: e.target.value });
const save = () => form.transform((d) => ({ ...d, rows: d.rows.filter((r) => r.check_in || r.status).map(({ employee_id, check_in, check_out, status }) => ({ employee_id, check_in: check_in || null, check_out: check_out || null, status: status || null })) })).post(route('hr.attendance.store'), { preserveScroll: true });
const statusLabel = computed(() => ({ present: t('hr2_hr_attendance.present'), absent: t('hr2_hr_attendance.absent'), leave: t('hr2_hr_attendance.leave'), holiday: t('hr2_hr_attendance.public_holiday'), weekend: t('hr2_hr_attendance.weekly_rest') }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_attendance.attendance')" />
        <PageHeader :title="$t('hr_attendance.attendance')" :subtitle="$t('hr_attendance.lateness_and_overtime_follow_the')" :crumbs="[{ label: $t('hr2_hr_attendance.human_resources') }, { label: $t('hr2_hr_attendance.attendance') }]">
            <template #actions>
                <DatePicker :model-value="date" @change="changeDate" class="rounded-xl border-slate-200 text-xs" />
                <div v-if="$can('hr.attendance.create')" class="flex items-center gap-2">
                <input type="file" accept=".csv,.txt" @input="importForm.file = $event.target.files[0]" class="text-xs" />
                <button @click="importForm.post(route('hr.attendance.import'), { forceFormData: true })" :disabled="!importForm.file" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold disabled:opacity-40">{{ $t('hr_attendance.import_employee_number_date_in_o') }}</button>
            </div>
            </template>
        </PageHeader>
        <div class="flex flex-wrap gap-3 mb-3 text-xs">
            <span v-for="s in summary" :key="s.status" class="px-3 py-1 rounded-lg bg-white border border-slate-200">{{ $t('hr2_hr_attendance.n_month', { n0: statusLabel[s.status] }) }} <b>{{ s.total }}</b></span>
        </div>
        <p v-for="(m, k) in importForm.errors" :key="k" class="text-xs text-rose-600 mb-2">{{ m }}</p>
        <p v-if="form.errors.rows" class="text-xs text-rose-600 mb-2">{{ form.errors.rows }}</p>
        <div v-if="$can('hr.attendance.create')" class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 text-xs flex flex-wrap items-center gap-3" data-att-bulk>
            <button type="button" @click="toggleAll" class="px-3 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200" data-att-select-all>{{ $t('hr_attendance.select_all') }}</button>
            <span class="font-bold text-slate-600">{{ $t('hr_attendance.selected_n', { n: selected.length }) }}</span>
            <template v-if="selected.length">
                <select v-model="bulk.status" class="rounded-lg border-slate-200 text-xs"><option value="">{{ $t('hr_attendance.pick_status') }}</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select>
                <input v-model="bulk.check_in" type="time" class="rounded-lg border-slate-200 text-xs" />
                <input v-model="bulk.check_out" type="time" class="rounded-lg border-slate-200 text-xs" />
                <button type="button" @click="applyBulk()" class="px-3 py-2 rounded-xl bg-indigo-50 text-indigo-700 font-bold hover:bg-indigo-100" data-att-apply>{{ $t('hr_attendance.apply_selected') }}</button>
                <button type="button" @click="applyBulk({ status: 'present' })" class="px-3 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold hover:bg-emerald-100" data-att-present>{{ $t('hr_attendance.bulk_all_present') }}</button>
            </template>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th v-if="$can('hr.attendance.create')" class="px-3 py-2 w-8"></th><th class="text-start px-3 py-2">{{ $t('hr_attendance.employee') }}</th><th class="px-3 py-2 w-28">{{ $t('hr_attendance.clock_in') }}</th><th class="px-3 py-2 w-28">{{ $t('hr_attendance.clock_out') }}</th><th class="px-3 py-2 w-36">{{ $t('hr_attendance.status') }}</th><th class="px-3 py-2">{{ $t('hr_attendance.late_overtime') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in form.rows" :key="r.employee_id" class="border-t border-slate-100" data-att-row>
                        <td v-if="$can('hr.attendance.create')" class="px-3 py-1.5"><input v-model="selected" type="checkbox" :value="r.employee_id" class="rounded border-slate-300" data-att-check /></td>
                        <td class="px-3 py-1.5">{{ r.name }} <span class="text-slate-400 font-mono">{{ r.number }}</span></td>
                        <td class="px-3 py-1.5"><input v-model="r.check_in" type="time" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                        <td class="px-3 py-1.5"><input v-model="r.check_out" type="time" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                        <td class="px-3 py-1.5"><select v-model="r.status" class="w-full rounded-lg border-slate-200 text-xs"><option value="">{{ $t('hr_attendance.automatic') }}</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select></td>
                        <td class="px-3 py-1.5 text-center font-mono"><span class="text-rose-600">{{ records[r.employee_id]?.late_minutes || '' }}</span> / <span class="text-emerald-600">{{ records[r.employee_id]?.overtime_minutes || '' }}</span></td>
                    </tr>
                </tbody>
            </table>
            <div v-if="$can('hr.attendance.create')" class="p-3 text-end"><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('hr_attendance.save_attendance') }}</button></div>
        </div>
    </AppLayout>
</template>

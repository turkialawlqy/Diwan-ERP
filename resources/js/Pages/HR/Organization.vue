<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

defineProps({ departments: Array, positions: Array, shifts: Array, costCenters: Array });
const dept = useForm({ name: '', parent_id: '', cost_center_id: '' });
const pos = useForm({ title: '', grade: '' });
const shift = useForm({ name: '', start_time: '08:00', end_time: '17:00', break_minutes: 60, grace_minutes: 15, work_days: [7, 1, 2, 3, 4], is_default: false });
const days = computed(() => ([[7, t('hr2_hr_organization.sunday')], [1, t('hr2_hr_organization.monday')], [2, t('hr2_hr_organization.tuesday')], [3, t('hr2_hr_organization.wednesday')], [4, t('hr2_hr_organization.thursday')], [5, t('hr2_hr_organization.friday')], [6, t('hr2_hr_organization.saturday')]]));
const opts = { preserveScroll: true };
const inp = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_organization.organization')" />
        <PageHeader :title="$t('hr_organization.organization_and_shifts')" :crumbs="[{ label: $t('hr2_hr_organization.human_resources') }, { label: $t('hr2_hr_organization.organization') }]" />
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_organization.departments_and_sections') }}</h3>
                <div v-for="d in departments" :key="d.id" class="flex justify-between py-1 border-b border-slate-50"><span>{{ d.parent ? d.parent.name + ' / ' : '' }}<b>{{ d.name }}</b> <span class="text-slate-400">{{ d.cost_center?.code }}</span></span><span>{{ d.employees_count }}</span></div>
                <div v-if="$can('hr.organization.manage')" class="space-y-2 mt-3">
                    <input v-model="dept.name" :placeholder="$t('hr_organization.department_name')" :class="inp" />
                    <SelectCreate v-model="dept.parent_id" :options="departments" entity="department" :label="(d) => $t('hr2_hr_organization.under_n', { n0: d.name })" :placeholder="$t('hr_organization.parent_department')" />
                    <SelectCreate v-model="dept.cost_center_id" :options="costCenters" entity="cost_center" :label="(c) => `${c.code} ${c.name_ar}`" :placeholder="$t('hr_organization.no_cost_center')" />
                    <button @click="dept.post(route('hr.departments.store'), { ...opts, onSuccess: () => dept.reset() })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_organization.add') }}</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_organization.titles_and_grades') }}</h3>
                <div v-for="p in positions" :key="p.id" class="flex justify-between py-1 border-b border-slate-50"><b>{{ p.title }}</b><span class="text-slate-400">{{ p.grade }}</span></div>
                <div v-if="$can('hr.organization.manage')" class="grid grid-cols-3 gap-2 mt-3">
                    <input v-model="pos.title" :placeholder="$t('hr_organization.title')" class="col-span-2 rounded-xl border-slate-200 text-xs" />
                    <input v-model="pos.grade" :placeholder="$t('hr_organization.grade')" class="rounded-xl border-slate-200 text-xs" />
                    <button @click="pos.post(route('hr.positions.store'), { ...opts, onSuccess: () => pos.reset() })" class="col-span-3 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_organization.add') }}</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_organization.shifts') }}</h3>
                <div v-for="s in shifts" :key="s.id" class="py-1 border-b border-slate-50"><b>{{ s.name }}</b> <span class="font-mono">{{ s.start_time.substring(0, 5) }}–{{ s.end_time.substring(0, 5) }}</span> <span v-if="s.is_default" class="text-indigo-600">{{ $t('hr_organization.default') }}</span></div>
                <div v-if="$can('hr.organization.manage')" class="space-y-2 mt-3">
                    <input v-model="shift.name" :placeholder="$t('hr_organization.shift_name')" :class="inp" />
                    <div class="grid grid-cols-2 gap-2"><input v-model="shift.start_time" type="time" :class="inp" /><input v-model="shift.end_time" type="time" :class="inp" /></div>
                    <div class="grid grid-cols-2 gap-2"><label>{{ $t('hr_organization.break_min') }}<input v-model.number="shift.break_minutes" type="text" inputmode="decimal" data-num :class="inp" /></label><label>{{ $t('hr_organization.lateness_grace_min') }}<input v-model.number="shift.grace_minutes" type="text" inputmode="decimal" data-num :class="inp" /></label></div>
                    <div class="flex flex-wrap gap-2"><label v-for="[n, l] in days" :key="n" class="flex items-center gap-1"><input v-model="shift.work_days" :value="n" type="checkbox" class="rounded" /> {{ l }}</label></div>
                    <label class="flex items-center gap-1"><input v-model="shift.is_default" type="checkbox" class="rounded" /> {{ $t('hr_organization.default_shift') }}</label>
                    <p v-for="(m, k) in shift.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="shift.post(route('hr.shifts.store'), { ...opts, onSuccess: () => shift.reset('name') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_organization.add') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmAction } from '@/composables/useDialog';
import { date, today } from '@/utils/format';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({ groups: Object, leaveTypes: Array, bankFormats: Object, holidays: Array, errors: Object });
const holiday = useForm({ name: '', start_date: '', end_date: '' });
const year = today().substring(0, 4);
// Fixed-date holidays; the two Eids follow the Hijri calendar, so their dates are entered each year.
const presets = [['اليوم الوطني', `${year}-09-23`, `${year}-09-23`], ['يوم التأسيس', `${year}-02-22`, `${year}-02-22`], ['عطلة عيد الفطر', '', ''], ['عطلة عيد الأضحى', '', '']];
const usePreset = ([name, start, end]) => Object.assign(holiday, { name, start_date: start, end_date: end });
const removeHoliday = async (h) => { if (await confirmAction(t('hrset.del_q', { name: h.name }), { danger: true, confirmText: t('hrset.del') })) router.delete(route('hr.holidays.destroy', h.id), { preserveScroll: true }); };

// Editable copies per group.
const forms = reactive(Object.fromEntries(Object.entries(props.groups).map(([k, g]) => [k, JSON.parse(JSON.stringify(g.value))])));
const types = reactive(props.leaveTypes.map((t) => ({ ...t })));

const save = (group) => router.put(route('hr.settings.update', group), { value: forms[group] }, { preserveScroll: true });
const reset = (group) => { if (confirm(t('hrset.reset_q'))) router.delete(route('hr.settings.reset', group), { preserveScroll: true, onSuccess: () => Object.assign(forms[group], JSON.parse(JSON.stringify(props.groups[group].value))) }); };
const saveType = (t) => router.put(route('hr.leave-types.update', t.id), t, { preserveScroll: true });
const err = (key) => props.errors?.[`value.${key}`];

const scalars = computed(() => [
    ['hr.month_days', t('hrset.s_month_days'), t('hrset.s_month_days_h'), ''],
    ['hr.hours_per_day', t('hrset.s_hours'), t('hrset.s_hours_h'), t('hrset.u_hour')],
    ['hr.overtime_premium', t('hrset.s_ot'), t('hrset.s_ot_h'), '%'],
    ['hr.max_deduction_ratio', t('hrset.s_ded'), t('hrset.s_ded_h'), '%'],
]);
const inp = 'w-full rounded-xl border-slate-200 text-xs font-mono';
</script>

<template>
    <AppLayout>
        <Head :title="$t('hrset.payroll_settings')" />
        <PageHeader :title="$t('hrset.payroll_and_labour_law_settings')" :subtitle="$t('hrset.company_values_fields_not_custom')" :crumbs="[{ label: $t('hrset.c_hr') }, { label: $t('hrset.c_payroll') }]" />
        <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 flex gap-2">
            <ExclamationTriangleIcon class="w-5 h-5 flex-shrink-0" />
            <span>{{ $t('hrset.changing_these_values_changes_th') }}</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <!-- GOSI (Saudi branches) -->
            <div v-if="$feature('gosi')" class="bg-white border border-slate-200 rounded-2xl p-4 lg:row-span-2">
                <div class="flex justify-between mb-3"><h3 class="font-black text-slate-700">{{ $t('hrset.social_insurance') }}</h3><span v-if="groups['hr.gosi'].overridden" class="text-indigo-600 font-bold">{{ $t('hrset.customised_for_the_company') }}</span></div>
                <div class="grid grid-cols-2 gap-3">
                    <label v-for="[k, l] in [['saudi_employee_rate', $t('hrset.r_s_emp')], ['saudi_employer_rate', $t('hrset.r_s_er')], ['non_saudi_employee_rate', $t('hrset.r_ns_emp')], ['non_saudi_employer_rate', $t('hrset.r_ns_er')], ['base_annuity_rate', $t('hrset.r_annuity')], ['min_wage', $t('hrset.r_min_wage')], ['max_wage', $t('hrset.r_max_wage')]]" :key="k" class="block">
                        {{ l }} <span class="text-slate-400">{{ $t('hrset.statutory', { value: groups['hr.gosi'].default[k] }) }}</span>
                        <input v-model.number="forms['hr.gosi'][k]" type="text" inputmode="decimal" data-num step="0.01" :class="inp" />
                        <span v-if="err(k)" class="text-rose-600">{{ err(k)[0] }}</span>
                    </label>
                    <label class="block">{{ $t('hrset.start_of_the_new_system_new_subs') }}<DatePicker v-model="forms['hr.gosi'].new_law_start" :class="inp" /></label>
                </div>
                <h4 class="font-bold mt-4 mb-2">{{ $t('hrset.pension_rate_step_up_for_new_sub') }}</h4>
                <div v-for="(row, i) in forms['hr.gosi'].new_entrant_annuity" :key="i" class="flex gap-2 mb-1.5 items-center">
                    <span>{{ $t('hrset.from') }}</span><DatePicker v-model="row.from" class="rounded-xl border-slate-200 text-xs" />
                    <input v-model.number="row.rate" type="text" inputmode="decimal" data-num step="0.01" class="w-24 min-w-0 rounded-xl border-slate-200 text-xs font-mono" /><span>%</span>
                    <button @click="forms['hr.gosi'].new_entrant_annuity.splice(i, 1)" class="text-rose-600">✕</button>
                </div>
                <button @click="forms['hr.gosi'].new_entrant_annuity.push({ from: '', rate: 0 })" class="text-indigo-600 font-bold">{{ $t('hrset.step') }}</button>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-4">
                    <button @click="save('hr.gosi')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.save') }}</button>
                    <button v-if="groups['hr.gosi'].overridden" @click="reset('hr.gosi')" class="px-3 py-2 rounded-xl border border-slate-200 font-bold">{{ $t('hrset.restore_statutory') }}</button>
                </div>
            </div>

            <!-- Scalars -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                <h3 class="font-black text-slate-700">{{ $t('hrset.pay_and_time') }}</h3>
                <div v-for="[g, label, hint, unit] in scalars" :key="g" class="flex items-end gap-2">
                    <label class="flex-1 block">{{ label }} <span class="text-slate-400">— {{ hint }} {{ $t('hrset.statutory', { value: groups[g].default.value + unit }) }}</span>
                        <input v-model.number="forms[g].value" type="text" inputmode="decimal" data-num step="0.01" :class="inp" />
                    </label>
                    <template v-if="$can('hr.settings.manage')">
                        <button @click="save(g)" class="px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">{{ $t('hrset.save') }}</button>
                        <button v-if="groups[g].overridden" @click="reset(g)" class="px-2 py-2 rounded-xl border border-slate-200" :title="$t('hrset.restore_statutory')">↺</button>
                    </template>
                </div>
                <p v-if="err('value')" class="text-rose-600">{{ err('value')[0] }}</p>
            </div>

            <!-- Annual leave -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-3">{{ $t('hrset.annual_leave') }}</h3>
                <div class="grid grid-cols-3 gap-2">
                    <label>{{ $t('hrset.days_per_year') }}<input v-model.number="forms['hr.annual_leave'].days" type="text" inputmode="decimal" data-num :class="inp" /></label>
                    <label>{{ $t('hrset.after_the_length_of_service') }}<input v-model.number="forms['hr.annual_leave'].senior_days" type="text" inputmode="decimal" data-num :class="inp" /></label>
                    <label>{{ $t('hrset.after_years') }}<input v-model.number="forms['hr.annual_leave'].senior_after_years" type="text" inputmode="decimal" data-num :class="inp" /></label>
                </div>
                <p v-for="k in ['days', 'senior_days', 'senior_after_years']" :key="k" class="text-rose-600">{{ err(k)?.[0] }}</p>
                <p class="text-slate-400 mt-1">{{ $t('hrset.statutory_colon', { days: groups['hr.annual_leave'].default.days, senior: groups['hr.annual_leave'].default.senior_days, years: groups['hr.annual_leave'].default.senior_after_years }) }}</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.annual_leave')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.save') }}</button><button v-if="groups['hr.annual_leave'].overridden" @click="reset('hr.annual_leave')" class="px-3 py-2 rounded-xl border font-bold">{{ $t('hrset.restore_statutory') }}</button></div>
            </div>

            <!-- Sick tiers -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-3">{{ $t('hrset.sick_leave_tiers_within_the_year') }}</h3>
                <div v-for="(t, i) in forms['hr.sick_tiers'].tiers" :key="i" class="flex gap-2 mb-1.5 items-center">
                    <span class="w-16">{{ $t('hrset.tier_n', { n: i + 1 }) }}</span>
                    <input v-model.number="t.days" type="text" inputmode="decimal" data-num class="w-20 min-w-0 rounded-xl border-slate-200 text-xs font-mono" /><span>{{ $t('hrset.days_at_pay') }}</span>
                    <input v-model.number="t.pay" type="text" inputmode="decimal" data-num class="w-20 min-w-0 rounded-xl border-slate-200 text-xs font-mono" /><span>%</span>
                    <button @click="forms['hr.sick_tiers'].tiers.splice(i, 1)" class="text-rose-600">✕</button>
                </div>
                <button @click="forms['hr.sick_tiers'].tiers.push({ days: 30, pay: 0 })" class="text-indigo-600 font-bold">{{ $t('hrset.tier') }}</button>
                <p class="text-slate-400 mt-1">{{ $t('hrset.statutory_art_117_30_days_at_ful') }}</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.sick_tiers')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.save') }}</button><button v-if="groups['hr.sick_tiers'].overridden" @click="reset('hr.sick_tiers')" class="px-3 py-2 rounded-xl border font-bold">{{ $t('hrset.restore_statutory') }}</button></div>
            </div>

            <!-- EOS -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-3">{{ $t('hrset.end_of_service_award') }}</h3>
                <div class="grid grid-cols-3 gap-2">
                    <label>{{ $t('hrset.first_years') }}<input v-model.number="forms['hr.end_of_service'].first_years" type="text" inputmode="decimal" data-num :class="inp" /></label>
                    <label>{{ $t('hrset.month_s_pay_for_each_of_them') }}<input v-model.number="forms['hr.end_of_service'].first_rate" type="text" inputmode="decimal" data-num step="0.05" :class="inp" /></label>
                    <label>{{ $t('hrset.for_each_year_after') }}<input v-model.number="forms['hr.end_of_service'].later_rate" type="text" inputmode="decimal" data-num step="0.05" :class="inp" /></label>
                </div>
                <h4 class="font-bold mt-3 mb-1">{{ $t('hrset.resignation_art_85_share_of_the') }}</h4>
                <div v-for="(r, i) in forms['hr.end_of_service'].resignation" :key="i" class="flex gap-2 mb-1.5 items-center">
                    <span>{{ $t('hrset.less_than') }}</span><input v-model.number="r.below" type="text" inputmode="decimal" data-num class="w-20 min-w-0 rounded-xl border-slate-200 text-xs font-mono" /><span>{{ $t('hrset.years') }}</span>
                    <input v-model.number="r.share" type="text" inputmode="decimal" data-num step="0.01" class="w-24 min-w-0 rounded-xl border-slate-200 text-xs font-mono" /><span>%</span>
                    <button @click="forms['hr.end_of_service'].resignation.splice(i, 1)" class="text-rose-600">✕</button>
                </div>
                <button @click="forms['hr.end_of_service'].resignation.push({ below: 0, share: 0 })" class="text-indigo-600 font-bold">{{ $t('hrset.tier') }}</button>
                <p class="text-slate-400 mt-1">{{ $t('hrset.beyond_the_last_tier_the_full_aw') }}</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.end_of_service')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.save') }}</button><button v-if="groups['hr.end_of_service'].overridden" @click="reset('hr.end_of_service')" class="px-3 py-2 rounded-xl border font-bold">{{ $t('hrset.restore_statutory') }}</button></div>
            </div>

            <!-- Public holidays -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-1">{{ $t('hrset.official_holidays_art_112') }}</h3>
                <p class="text-slate-400 mb-3">{{ $t('hrset.their_days_are_not_deducted_from') }}</p>
                <div class="flex flex-wrap gap-1.5 mb-2"><button v-for="p in presets" :key="p[0]" @click="usePreset(p)" class="px-2 py-1 rounded-lg border border-slate-200">{{ p[0] }}</button></div>
                <!-- From lg this card is half the page: back to two columns so whole dates fit. -->
                <div v-if="$can('hr.settings.manage')" class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-2 gap-2 items-end">
                    <input v-model="holiday.name" :placeholder="$t('hrset.name')" class="col-span-2 sm:col-span-1 lg:col-span-2 rounded-xl border-slate-200 text-xs" />
                    <DatePicker v-model="holiday.start_date" class="w-full rounded-xl border-slate-200 text-xs" />
                    <DatePicker v-model="holiday.end_date" class="w-full rounded-xl border-slate-200 text-xs" />
                    <button @click="holiday.post(route('hr.holidays.store'), { preserveScroll: true, onSuccess: () => holiday.reset() })" :disabled="holiday.processing" class="px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.add') }}</button>
                </div>
                <p v-for="k in ['name', 'start_date', 'end_date']" :key="k" class="text-rose-600">{{ holiday.errors[k] }}</p>
                <div v-for="h in holidays" :key="h.id" class="flex justify-between py-1 border-b border-slate-50 mt-1">
                    <span>{{ h.name }} <span class="font-mono text-slate-400">{{ date(h.start_date) }} → {{ date(h.end_date) }}</span></span>
                    <button v-if="$can('hr.settings.manage')" @click="removeHoliday(h)" class="text-rose-600">✕</button>
                </div>
            </div>

            <!-- Late deduction -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-1">{{ $t('hrset.lateness_deduction') }}</h3>
                <p class="text-slate-400 mb-3">{{ $t('hrset.enable_it_only_if_your_company_s') }}</p>
                <label class="flex items-center gap-2 mb-2"><input v-model="forms['hr.late_deduction'].enabled" type="checkbox" class="rounded" /> {{ $t('hrset.deduct_late_minutes_from_salary') }}</label>
                <label class="block">{{ $t('hrset.monthly_grace_minutes') }}<input v-model.number="forms['hr.late_deduction'].monthly_grace_minutes" type="text" inputmode="decimal" data-num min="0" :class="inp" /></label>
                <p v-if="err('monthly_grace_minutes')" class="text-rose-600">{{ err('monthly_grace_minutes')[0] }}</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.late_deduction')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.save') }}</button></div>
            </div>

            <!-- Wage protection / Mudad (Saudi branches) -->
            <div v-if="$feature('wps')" class="bg-white border border-slate-200 rounded-2xl p-4 lg:col-span-2">
                <h3 class="font-black text-slate-700 mb-1">{{ $t('hrset.wage_protection_mudad_and_bank_f') }}</h3>
                <p class="text-slate-400 mb-3">{{ $t('hrset.printed_on_the_mudad_file_and_th') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <label class="block">{{ $t('hrset.establishment_number_at_the_mini') }}<input v-model="forms['hr.wps'].mol_establishment_id" :class="inp" dir="ltr" placeholder="1-1234567" /><span v-if="err('mol_establishment_id')" class="text-rose-600">{{ err('mol_establishment_id')[0] }}</span></label>
                    <label class="block">{{ $t('hrset.establishment_number_at_social_i') }}<input v-model="forms['hr.wps'].gosi_establishment_id" :class="inp" dir="ltr" /><span v-if="err('gosi_establishment_id')" class="text-rose-600">{{ err('gosi_establishment_id')[0] }}</span></label>
                    <label class="block">{{ $t('hrset.payroll_account_iban') }}<input v-model="forms['hr.wps'].company_iban" :class="inp" dir="ltr" placeholder="SA..." /><span v-if="err('company_iban')" class="text-rose-600">{{ err('company_iban')[0] }}</span></label>
                    <label class="block">{{ $t('hrset.bank_file_format') }}
                        <select v-model="forms['hr.wps'].bank_format" :class="inp"><option v-for="(label, key) in bankFormats" :key="key" :value="key">{{ label }}</option></select>
                    </label>
                </div>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.wps')" class="px-6 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hrset.save') }}</button></div>
            </div>

            <!-- Leave types -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 lg:col-span-2">
                <h3 class="font-black text-slate-700 mb-3">{{ $t('hrset.leave_types') }}</h3>
                <div class="overflow-x-auto"><table class="w-full">
                    <thead class="text-slate-500"><tr><th class="text-start">{{ $t('hrset.type') }}</th><th>{{ $t('hrset.days_per_year_0_by_case') }}</th><th>{{ $t('hrset.pay') }}</th><th>{{ $t('hrset.deducted_from_the_annual_balance') }}</th><th>{{ $t('hrset.active') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="t in types" :key="t.id" class="border-t border-slate-100">
                            <td class="py-1.5"><input v-model="t.name" class="rounded-xl border-slate-200 text-xs" /></td>
                            <td class="text-center"><input v-model.number="t.days_per_year" type="text" inputmode="decimal" data-num class="w-20 rounded-xl border-slate-200 text-xs font-mono" /></td>
                            <td class="text-center"><select v-model="t.pay_rule" class="rounded-xl border-slate-200 text-xs"><option value="full">{{ $t('hrset.paid') }}</option><option value="sick_tiers">{{ $t('hrset.sick_tiers') }}</option><option value="unpaid">{{ $t('hrset.unpaid') }}</option></select></td>
                            <td class="text-center"><input v-model="t.deducts_balance" type="checkbox" class="rounded" /></td>
                            <td class="text-center"><input v-model="t.is_active" type="checkbox" class="rounded" /></td>
                            <td class="text-end"><button v-if="$can('hr.settings.manage')" @click="saveType(t)" class="text-indigo-600 font-bold">{{ $t('hrset.save') }}</button></td>
                        </tr>
                    </tbody>
                </table></div>
            </div>
        </div>
    </AppLayout>
</template>

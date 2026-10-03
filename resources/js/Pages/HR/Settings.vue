<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ groups: Object, leaveTypes: Array, errors: Object });

// Editable copies per group.
const forms = reactive(Object.fromEntries(Object.entries(props.groups).map(([k, g]) => [k, JSON.parse(JSON.stringify(g.value))])));
const types = reactive(props.leaveTypes.map((t) => ({ ...t })));

const save = (group) => router.put(route('hr.settings.update', group), { value: forms[group] }, { preserveScroll: true });
const reset = (group) => { if (confirm('استعادة القيمة النظامية الافتراضية؟')) router.delete(route('hr.settings.reset', group), { preserveScroll: true, onSuccess: () => Object.assign(forms[group], JSON.parse(JSON.stringify(props.groups[group].value))) }); };
const saveType = (t) => router.put(route('hr.leave-types.update', t.id), t, { preserveScroll: true });
const err = (key) => props.errors?.[`value.${key}`];

const scalars = [
    ['hr.month_days', 'أيام شهر الراتب', 'لحساب الأجر اليومي (الغياب، الإجازات، نهاية الخدمة)', ''],
    ['hr.hours_per_day', 'ساعات العمل اليومية', 'لحساب أجر الساعة (م 98)', 'ساعة'],
    ['hr.overtime_premium', 'علاوة العمل الإضافي', 'نسبة من الأجر الأساسي للساعة تضاف لأجر الساعة (م 107: 50%)', '%'],
    ['hr.max_deduction_ratio', 'الحد الأقصى للاستقطاع', 'من الأجر للسلف والجزاءات (م 92: 50%)', '%'],
];
const inp = 'w-full rounded-xl border-slate-200 text-xs font-mono';
</script>

<template>
    <AppLayout>
        <Head title="إعدادات الرواتب" />
        <PageHeader title="إعدادات الرواتب ونظام العمل" subtitle="قيم المنشأة؛ الحقول غير المعدّلة تتبع القيم النظامية الافتراضية للمنصة" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'إعدادات الرواتب' }]" />
        <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 flex gap-2">
            <ExclamationTriangleIcon class="w-5 h-5 flex-shrink-0" />
            <span>تعديل هذه القيم يغيّر احتساب الرواتب والتأمينات ونهاية الخدمة للمسيرات الجديدة فقط. تأكد من مطابقتها لتعاميم التأمينات الاجتماعية ونظام العمل وعقود موظفيك.</span>
        </div>

        <div class="grid lg:grid-cols-2 gap-4 text-xs">
            <!-- GOSI -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 lg:row-span-2">
                <div class="flex justify-between mb-3"><h3 class="font-black text-slate-700">التأمينات الاجتماعية</h3><span v-if="groups['hr.gosi'].overridden" class="text-indigo-600 font-bold">معدّلة للمنشأة</span></div>
                <div class="grid grid-cols-2 gap-3">
                    <label v-for="[k, l] in [['saudi_employee_rate', 'سعودي — حصة الموظف %'], ['saudi_employer_rate', 'سعودي — حصة المنشأة %'], ['non_saudi_employee_rate', 'غير سعودي — حصة الموظف %'], ['non_saudi_employer_rate', 'غير سعودي — حصة المنشأة (الأخطار) %'], ['base_annuity_rate', 'نسبة المعاش المضمّنة في النسب أعلاه %'], ['min_wage', 'الحد الأدنى للأجر الخاضع'], ['max_wage', 'الحد الأعلى للأجر الخاضع']]" :key="k" class="block">
                        {{ l }} <span class="text-slate-400">(النظامي {{ groups['hr.gosi'].default[k] }})</span>
                        <input v-model="forms['hr.gosi'][k]" type="number" step="0.01" :class="inp" />
                        <span v-if="err(k)" class="text-rose-600">{{ err(k)[0] }}</span>
                    </label>
                    <label class="block">بدء النظام الجديد (المشتركون الجدد)<input v-model="forms['hr.gosi'].new_law_start" type="date" :class="inp" /></label>
                </div>
                <h4 class="font-bold mt-4 mb-2">تدرّج نسبة المعاش للمشتركين الجدد (لكل طرف)</h4>
                <div v-for="(row, i) in forms['hr.gosi'].new_entrant_annuity" :key="i" class="flex gap-2 mb-1.5 items-center">
                    <span>من</span><input v-model="row.from" type="date" class="rounded-xl border-slate-200 text-xs" />
                    <input v-model="row.rate" type="number" step="0.01" class="w-24 rounded-xl border-slate-200 text-xs font-mono" /><span>%</span>
                    <button @click="forms['hr.gosi'].new_entrant_annuity.splice(i, 1)" class="text-rose-600">✕</button>
                </div>
                <button @click="forms['hr.gosi'].new_entrant_annuity.push({ from: '', rate: 0 })" class="text-indigo-600 font-bold">+ مرحلة</button>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-4">
                    <button @click="save('hr.gosi')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button>
                    <button v-if="groups['hr.gosi'].overridden" @click="reset('hr.gosi')" class="px-3 py-2 rounded-xl border border-slate-200 font-bold">استعادة النظامي</button>
                </div>
            </div>

            <!-- Scalars -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                <h3 class="font-black text-slate-700">الأجر والوقت</h3>
                <div v-for="[g, label, hint, unit] in scalars" :key="g" class="flex items-end gap-2">
                    <label class="flex-1 block">{{ label }} <span class="text-slate-400">— {{ hint }} (النظامي {{ groups[g].default.value }}{{ unit }})</span>
                        <input v-model="forms[g].value" type="number" step="0.01" :class="inp" />
                    </label>
                    <template v-if="$can('hr.settings.manage')">
                        <button @click="save(g)" class="px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">حفظ</button>
                        <button v-if="groups[g].overridden" @click="reset(g)" class="px-2 py-2 rounded-xl border border-slate-200" title="استعادة النظامي">↺</button>
                    </template>
                </div>
                <p v-if="err('value')" class="text-rose-600">{{ err('value')[0] }}</p>
            </div>

            <!-- Annual leave -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-3">الإجازة السنوية</h3>
                <div class="grid grid-cols-3 gap-2">
                    <label>الأيام سنوياً<input v-model="forms['hr.annual_leave'].days" type="number" :class="inp" /></label>
                    <label>بعد مدة الخدمة<input v-model="forms['hr.annual_leave'].senior_days" type="number" :class="inp" /></label>
                    <label>بعد (سنوات)<input v-model="forms['hr.annual_leave'].senior_after_years" type="number" :class="inp" /></label>
                </div>
                <p v-for="k in ['days', 'senior_days', 'senior_after_years']" :key="k" class="text-rose-600">{{ err(k)?.[0] }}</p>
                <p class="text-slate-400 mt-1">النظامي: {{ groups['hr.annual_leave'].default.days }} يوماً، و{{ groups['hr.annual_leave'].default.senior_days }} بعد {{ groups['hr.annual_leave'].default.senior_after_years }} سنوات (م 109). يمكن لعقد الموظف منح أكثر من ملفه.</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.annual_leave')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button><button v-if="groups['hr.annual_leave'].overridden" @click="reset('hr.annual_leave')" class="px-3 py-2 rounded-xl border font-bold">استعادة النظامي</button></div>
            </div>

            <!-- Sick tiers -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-3">شرائح الإجازة المرضية (خلال السنة)</h3>
                <div v-for="(t, i) in forms['hr.sick_tiers'].tiers" :key="i" class="flex gap-2 mb-1.5 items-center">
                    <span class="w-16">شريحة {{ i + 1 }}</span>
                    <input v-model="t.days" type="number" class="w-20 rounded-xl border-slate-200 text-xs font-mono" /><span>يوماً بأجر</span>
                    <input v-model="t.pay" type="number" class="w-20 rounded-xl border-slate-200 text-xs font-mono" /><span>%</span>
                    <button @click="forms['hr.sick_tiers'].tiers.splice(i, 1)" class="text-rose-600">✕</button>
                </div>
                <button @click="forms['hr.sick_tiers'].tiers.push({ days: 30, pay: 0 })" class="text-indigo-600 font-bold">+ شريحة</button>
                <p class="text-slate-400 mt-1">النظامي (م 117): 30 يوماً كاملة، 60 بثلاثة أرباع، 30 بدون أجر.</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.sick_tiers')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button><button v-if="groups['hr.sick_tiers'].overridden" @click="reset('hr.sick_tiers')" class="px-3 py-2 rounded-xl border font-bold">استعادة النظامي</button></div>
            </div>

            <!-- EOS -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-3">مكافأة نهاية الخدمة</h3>
                <div class="grid grid-cols-3 gap-2">
                    <label>أول (سنوات)<input v-model="forms['hr.end_of_service'].first_years" type="number" :class="inp" /></label>
                    <label>أجر الشهر لكل سنة منها<input v-model="forms['hr.end_of_service'].first_rate" type="number" step="0.05" :class="inp" /></label>
                    <label>لكل سنة بعدها<input v-model="forms['hr.end_of_service'].later_rate" type="number" step="0.05" :class="inp" /></label>
                </div>
                <h4 class="font-bold mt-3 mb-1">الاستقالة (م 85): نسبة المستحق حسب مدة الخدمة</h4>
                <div v-for="(r, i) in forms['hr.end_of_service'].resignation" :key="i" class="flex gap-2 mb-1.5 items-center">
                    <span>أقل من</span><input v-model="r.below" type="number" class="w-20 rounded-xl border-slate-200 text-xs font-mono" /><span>سنة:</span>
                    <input v-model="r.share" type="number" step="0.01" class="w-24 rounded-xl border-slate-200 text-xs font-mono" /><span>%</span>
                    <button @click="forms['hr.end_of_service'].resignation.splice(i, 1)" class="text-rose-600">✕</button>
                </div>
                <button @click="forms['hr.end_of_service'].resignation.push({ below: 0, share: 0 })" class="text-indigo-600 font-bold">+ شريحة</button>
                <p class="text-slate-400 mt-1">ما تجاوز آخر شريحة يستحق المكافأة كاملة.</p>
                <div v-if="$can('hr.settings.manage')" class="flex gap-2 mt-3"><button @click="save('hr.end_of_service')" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button><button v-if="groups['hr.end_of_service'].overridden" @click="reset('hr.end_of_service')" class="px-3 py-2 rounded-xl border font-bold">استعادة النظامي</button></div>
            </div>

            <!-- Leave types -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 lg:col-span-2">
                <h3 class="font-black text-slate-700 mb-3">أنواع الإجازات</h3>
                <table class="w-full">
                    <thead class="text-slate-500"><tr><th class="text-start">النوع</th><th>الأيام سنوياً (0 = حسب الحالة)</th><th>الأجر</th><th>تخصم من الرصيد السنوي</th><th>فعالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="t in types" :key="t.id" class="border-t border-slate-100">
                            <td class="py-1.5"><input v-model="t.name" class="rounded-xl border-slate-200 text-xs" /></td>
                            <td class="text-center"><input v-model="t.days_per_year" type="number" class="w-20 rounded-xl border-slate-200 text-xs font-mono" /></td>
                            <td class="text-center"><select v-model="t.pay_rule" class="rounded-xl border-slate-200 text-xs"><option value="full">مدفوعة</option><option value="sick_tiers">شرائح المرضية</option><option value="unpaid">بدون أجر</option></select></td>
                            <td class="text-center"><input v-model="t.deducts_balance" type="checkbox" class="rounded" /></td>
                            <td class="text-center"><input v-model="t.is_active" type="checkbox" class="rounded" /></td>
                            <td class="text-end"><button v-if="$can('hr.settings.manage')" @click="saveType(t)" class="text-indigo-600 font-bold">حفظ</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

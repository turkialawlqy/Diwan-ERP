<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, monthLabel } from '@/utils/format';

const props = defineProps({ asset: Object, monthlyAmount: Number, schedule: Array, bankAccounts: Array });
const methods = { straight_line: 'القسط الثابت', declining_balance: 'القسط المتناقص', units_of_production: 'وحدات الإنتاج' };
const units = props.asset.depreciation_method === 'units_of_production';
const impairOpen = ref(false);
const showAll = ref(false);
const impairForm = useForm({ date: new Date().toISOString().substring(0, 10), amount: '', reason: '' });
const impair = () => impairForm.post(route('accounting.fixed-assets.impair', props.asset.id), { preserveScroll: true, onSuccess: () => { impairOpen.value = false; impairForm.reset(); } });
const usageForm = useForm({ month: new Date().toISOString().substring(0, 7), units: '' });
const saveUsage = () => usageForm.post(route('accounting.fixed-assets.usage', props.asset.id), { preserveScroll: true, onSuccess: () => usageForm.reset('units') });
const usageOf = (period) => props.asset.usages?.find((u) => Number(u.period) === Number(period))?.units;
const disposeOpen = ref(false);
const form = useForm({ date: new Date().toISOString().substring(0, 10), proceeds: 0, method: 'bank_transfer', bank_account_id: '', taxable: false });
const dispose = () => form.transform((d) => ({ ...d, vat_amount: d.taxable ? Math.round((parseFloat(d.proceeds) || 0) * 15) / 100 : 0 })).post(route('accounting.fixed-assets.dispose', props.asset.id), { onSuccess: () => (disposeOpen.value = false) });
</script>

<template>
    <AppLayout>
        <Head :title="asset.name_ar" />
        <PageHeader :title="`${asset.code} - ${asset.name_ar}`" :subtitle="asset.category?.name_ar" :crumbs="[{ label: 'الأصول الثابتة', href: route('accounting.fixed-assets.index') }, { label: asset.code }]">
            <template #actions>
                <button v-if="asset.status !== 'disposed' && $can('accounting.fixed_assets.post')" @click="impairOpen = true" class="px-4 py-2 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">تسجيل هبوط قيمة</button>
                <button v-if="asset.status !== 'disposed' && $can('accounting.fixed_assets.delete')" @click="disposeOpen = true" class="px-4 py-2 rounded-xl border border-rose-300 text-rose-700 text-xs font-bold">بيع / استبعاد</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="التكلفة" :value="money(asset.cost)" />
            <StatCard :label="Number(asset.accumulated_impairment) > 0 ? `مجمع الإهلاك + هبوط القيمة ${money(asset.accumulated_impairment)}` : 'مجمع الإهلاك'" :value="money(Number(asset.accumulated_depreciation) + Number(asset.accumulated_impairment || 0))" tone="amber" />
            <StatCard label="القيمة الدفترية" :value="money(asset.book_value)" tone="emerald" />
            <StatCard :label="units ? 'إهلاك الشهر الحالي (حسب الاستخدام)' : 'قسط الإهلاك الشهري'" :value="money(monthlyAmount)" tone="indigo" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs space-y-2">
                <p><span class="text-slate-500">تاريخ الشراء:</span> <b class="font-mono">{{ date(asset.acquisition_date) }}</b></p>
                <p><span class="text-slate-500">بداية الإهلاك:</span> <b class="font-mono">{{ date(asset.depreciation_start_date) }}</b></p>
                <p v-if="units"><span class="text-slate-500">العمر بالوحدات:</span> <b class="font-mono">{{ money(asset.total_units) }} {{ asset.unit_label }}</b></p>
                <p v-else><span class="text-slate-500">العمر الإنتاجي:</span> <b>{{ asset.useful_life_months }} شهر</b></p>
                <p><span class="text-slate-500">الطريقة:</span> <b>{{ methods[asset.depreciation_method] }}</b></p>
                <p><span class="text-slate-500">القيمة التخريدية:</span> <b class="font-mono">{{ money(asset.salvage_value) }}</b></p>
                <p><span class="text-slate-500">الرقم التسلسلي:</span> <b>{{ asset.serial_number || '—' }}</b></p>
                <p><span class="text-slate-500">الموقع:</span> <b>{{ asset.location || '—' }}</b></p>
                <p v-if="asset.status === 'disposed'" class="text-rose-600 font-bold">تم الاستبعاد بتاريخ {{ date(asset.disposal_date) }} بمبلغ {{ money(asset.disposal_amount) }}</p>
                <div v-if="asset.impairments?.length" class="pt-2 border-t border-slate-100">
                    <p class="font-bold text-slate-700 mb-1">هبوط القيمة</p>
                    <p v-for="i in asset.impairments" :key="i.id" class="text-amber-700"><span class="font-mono">{{ date(i.date) }}</span> {{ money(i.amount) }} — {{ i.reason }}</p>
                </div>
                <form v-if="units && asset.status === 'active' && $can('accounting.fixed_assets.edit')" @submit.prevent="saveUsage" class="pt-2 border-t border-slate-100 space-y-2">
                    <p class="font-bold text-slate-700">استخدام الشهر ({{ asset.unit_label }})</p>
                    <p class="text-slate-400">إن كان الأصل مربوطاً بمعدة تؤخذ ساعات تشغيلها تلقائياً ما لم تُدخل قيمة هنا.</p>
                    <div class="flex gap-2"><input v-model="usageForm.month" type="month" class="rounded-xl border-slate-200 text-xs" /><input v-model="usageForm.units" type="number" step="0.01" min="0" class="w-24 rounded-xl border-slate-200 text-xs" /><button class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">حفظ</button></div>
                    <p v-if="usageForm.errors.units" class="text-rose-600">{{ usageForm.errors.units }}</p>
                </form>
            </div>
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">الشهر</th><th v-if="units" class="text-end px-4 py-2">الاستخدام</th><th class="text-end px-4 py-2">قسط الإهلاك</th></tr></thead>
                    <tbody>
                        <tr v-for="d in asset.depreciations" :key="d.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ monthLabel(d.period) }}</td><td v-if="units" class="px-4 py-2 text-end font-mono">{{ usageOf(d.period) ?? '—' }}</td><td class="px-4 py-2 text-end font-mono">{{ money(d.amount) }}</td></tr>
                        <tr v-if="!asset.depreciations.length"><td :colspan="units ? 3 : 2" class="py-8 text-center text-slate-400">لم يتم ترحيل إهلاك بعد</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="schedule.length" class="mt-4 bg-white border border-slate-200 rounded-2xl overflow-hidden" data-schedule>
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 text-xs">
                <h3 class="font-black text-slate-700">جدول الإهلاك المتوقع ({{ schedule.length }} شهر)</h3>
                <span class="text-slate-500">ينتهي {{ monthLabel(schedule[schedule.length - 1].period) }} بقيمة دفترية {{ money(schedule[schedule.length - 1].book_value) }}</span>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">الشهر</th><th class="text-end px-4 py-2">القسط</th><th class="text-end px-4 py-2">المجمع</th><th class="text-end px-4 py-2">القيمة الدفترية</th></tr></thead>
                <tbody>
                    <tr v-for="r in (showAll ? schedule : schedule.slice(0, 12))" :key="r.period" class="border-t border-slate-100">
                        <td class="px-4 py-1.5 font-mono">{{ monthLabel(r.period) }}</td><td class="px-4 py-1.5 text-end font-mono">{{ money(r.amount) }}</td>
                        <td class="px-4 py-1.5 text-end font-mono">{{ money(r.accumulated) }}</td><td class="px-4 py-1.5 text-end font-mono">{{ money(r.book_value) }}</td>
                    </tr>
                </tbody>
            </table></div>
            <button v-if="schedule.length > 12 && !showAll" @click="showAll = true" class="w-full py-2 text-xs font-bold text-indigo-600 border-t border-slate-100">عرض الجدول كاملاً</button>
        </div>
        <p v-else-if="units && asset.status === 'active'" class="mt-4 text-xs text-slate-500">إهلاك وحدات الإنتاج يتبع الاستخدام الفعلي لكل شهر، فلا يُعرض له جدول مستقبلي.</p>

        <Dialog :show="impairOpen" title="تسجيل هبوط قيمة الأصل" @close="impairOpen = false">
            <div class="space-y-3 text-xs">
                <p class="text-slate-500">عندما تقل القيمة القابلة للاسترداد عن القيمة الدفترية (تلف، تقادم، انخفاض السوق). يُرحَّل إهلاك الأشهر السابقة أولاً، ثم يُوزَّع المتبقي على باقي العمر.</p>
                <Field label="التاريخ" :error="impairForm.errors.date"><input v-model="impairForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مبلغ الهبوط" :error="impairForm.errors.amount" :hint="`القيمة الدفترية الحالية ${money(asset.book_value)}`"><input v-model="impairForm.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="السبب" :error="impairForm.errors.reason"><input v-model="impairForm.reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <template #footer><button @click="impair" :disabled="impairForm.processing" class="px-4 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold">ترحيل هبوط القيمة</button></template>
        </Dialog>

        <Dialog :show="disposeOpen" title="بيع / استبعاد الأصل" @close="disposeOpen = false">
            <div class="space-y-3 text-xs">
                <Field label="التاريخ" :error="form.errors.date"><input v-model="form.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مبلغ البيع (0 للإتلاف)" :error="form.errors.proceeds"><input v-model="form.proceeds" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="flex items-center gap-2"><input v-model="form.taxable" type="checkbox" class="rounded" /> بيع خاضع للضريبة {{ $taxRate() }}% ({{ ((parseFloat(form.proceeds) || 0) * $taxRate() / 100).toFixed(2) }})</label>
                <p v-if="form.errors.vat_amount" class="text-rose-600">{{ form.errors.vat_amount }}</p>
                <Field label="طريقة القبض"><select v-model="form.method" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">بنك</option><option value="cash">نقداً</option></select></Field>
                <Field label="الصندوق / البنك"><select v-model="form.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <p class="text-slate-500">يُحتسب إهلاك الأصل حتى تاريخ البيع أولاً، ثم الربح/الخسارة = مبلغ البيع (بدون الضريبة) − القيمة الدفترية (حالياً {{ money(asset.book_value) }}).</p>
            </div>
            <template #footer><button @click="dispose" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">تأكيد الاستبعاد</button></template>
        </Dialog>
    </AppLayout>
</template>

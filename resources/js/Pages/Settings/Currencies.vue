<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ base: String, currencies: Array, history: Array });

const currency = useForm({ code: '', name_ar: '', name_en: '', symbol_ar: '', exchange_rate: '' });
const rate = useForm({ currency_code: props.currencies.find((c) => c.code !== props.base)?.code || '', date: new Date().toISOString().substring(0, 10), rate: '' });

const addCurrency = () => currency.post(route('settings.currencies.store'), { preserveScroll: true, onSuccess: () => currency.reset() });
const addRate = () => rate.post(route('settings.currencies.rates'), { preserveScroll: true, onSuccess: () => rate.reset('rate') });
const remove = (c) => confirm(`حذف العملة ${c.code}؟`) && router.delete(route('settings.currencies.destroy', c.id), { preserveScroll: true });
const foreign = () => props.currencies.filter((c) => c.code !== props.base);
</script>

<template>
    <AppLayout>
        <Head title="العملات وأسعار الصرف" />
        <PageHeader title="العملات وأسعار الصرف" :subtitle="`العملة الأساسية للمنشأة: ${base} — القيود المحاسبية تُسجَّل دائماً بها`" :crumbs="[{ label: 'الإعدادات' }, { label: 'العملات' }]" />

        <div class="grid lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">العملة</th><th class="px-3 py-2">الرمز</th><th class="px-3 py-2">السعر الحالي</th><th class="px-3 py-2"></th></tr></thead>
                    <tbody>
                        <tr v-for="c in currencies" :key="c.id" class="border-t border-slate-100">
                            <td class="px-3 py-2 font-bold">{{ c.name_ar }} <span v-if="c.code === base" class="ms-1 px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700">أساسية</span></td>
                            <td class="px-3 py-2 text-center font-mono">{{ c.code }}</td>
                            <td class="px-3 py-2 text-center font-mono" dir="ltr">{{ c.code === base ? '1' : (c.current_rate ? `1 ${c.code} = ${Number(c.current_rate).toFixed(4)} ${base}` : '—') }}</td>
                            <td class="px-3 py-2 text-center"><button v-if="c.code !== base && $can('admin.settings.edit')" @click="remove(c)" class="text-rose-600 font-bold">حذف</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="$can('admin.settings.edit')" class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                    <h3 class="font-black text-slate-700">سعر صرف بتاريخ</h3>
                    <p class="text-slate-500">المستند يأخذ آخر سعر في تاريخه أو قبله، ويمكن تعديله في المستند نفسه.</p>
                    <Field label="العملة" :error="rate.errors.currency_code"><select v-model="rate.currency_code" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="c in foreign()" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name_ar }}</option></select></Field>
                    <Field label="التاريخ" :error="rate.errors.date"><input v-model="rate.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="`السعر (1 ${rate.currency_code || '...'} = ؟ ${base})`" :error="rate.errors.rate"><input v-model="rate.rate" type="number" step="0.000001" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <button @click="addRate" :disabled="rate.processing || !foreign().length" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50">حفظ السعر</button>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                    <h3 class="font-black text-slate-700">إضافة عملة</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <Field label="الرمز (ISO)" :error="currency.errors.code"><input v-model="currency.code" maxlength="3" dir="ltr" placeholder="USD" class="w-full rounded-xl border-slate-200 text-xs font-mono uppercase" /></Field>
                        <Field label="السعر الافتراضي" :error="currency.errors.exchange_rate"><input v-model="currency.exchange_rate" type="number" step="0.000001" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                        <Field label="الاسم" :error="currency.errors.name_ar" class="col-span-2"><input v-model="currency.name_ar" placeholder="دولار أمريكي" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                        <Field label="الرمز المختصر"><input v-model="currency.symbol_ar" placeholder="$" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                        <Field label="الاسم بالإنجليزية"><input v-model="currency.name_en" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    </div>
                    <button @click="addCurrency" :disabled="currency.processing" class="w-full px-3 py-2 rounded-xl border border-slate-300 font-bold">إضافة العملة</button>
                </div>
            </div>
        </div>

        <div class="mt-4 bg-white border border-slate-200 rounded-2xl overflow-hidden text-xs">
            <h3 class="px-4 py-3 font-black border-b border-slate-100">سجل أسعار الصرف</h3>
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">التاريخ</th><th class="px-3 py-2">العملة</th><th class="px-3 py-2">السعر</th></tr></thead>
                <tbody>
                    <tr v-for="h in history" :key="h.id" class="border-t border-slate-100"><td class="px-3 py-2 font-mono">{{ String(h.date).substring(0, 10) }}</td><td class="px-3 py-2 text-center font-mono">{{ h.currency_code }}</td><td class="px-3 py-2 text-center font-mono">{{ Number(h.rate).toFixed(6) }}</td></tr>
                    <tr v-if="!history.length"><td colspan="3" class="px-3 py-6 text-center text-slate-400">لا توجد أسعار مسجلة بعد؛ يُستخدم السعر الافتراضي لكل عملة</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

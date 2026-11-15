<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { today } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ base: String, currencies: Array, history: Array });

const currency = useForm({ code: '', name_ar: '', name_en: '', symbol_ar: '', exchange_rate: '' });
const rate = useForm({ currency_code: props.currencies.find((c) => c.code !== props.base)?.code || '', date: today(), rate: '' });

const addCurrency = () => currency.post(route('settings.currencies.store'), { preserveScroll: true, onSuccess: () => currency.reset() });
const addRate = () => rate.post(route('settings.currencies.rates'), { preserveScroll: true, onSuccess: () => rate.reset('rate') });
const remove = (c) => confirm(t('set2_settings_currencies.delete_the_currency_n', { n0: c.code })) && router.delete(route('settings.currencies.destroy', c.id), { preserveScroll: true });
const foreign = () => props.currencies.filter((c) => c.code !== props.base);
</script>

<template>
    <AppLayout>
        <Head :title="$t('set2_settings_currencies.currencies_and_exchange_rates')" />
        <PageHeader :title="$t('set2_settings_currencies.currencies_and_exchange_rates')" :subtitle="$t('set2_settings_currencies.the_company_s_base_currency_n_jo', { n0: base })" :crumbs="[{ label: $t('set2_settings_currencies.settings') }, { label: $t('set2_settings_currencies.currencies') }]" />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('set2_settings_currencies.currency') }}</th><th class="px-3 py-2">{{ $t('set2_settings_currencies.code') }}</th><th class="px-3 py-2">{{ $t('set2_settings_currencies.current_rate') }}</th><th class="px-3 py-2"></th></tr></thead>
                    <tbody>
                        <tr v-for="c in currencies" :key="c.id" class="border-t border-slate-100">
                            <td class="px-3 py-2 font-bold">{{ c.name_ar }} <span v-if="c.code === base" class="ms-1 px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700">{{ $t('set2_settings_currencies.base') }}</span></td>
                            <td class="px-3 py-2 text-center font-mono">{{ c.code }}</td>
                            <td class="px-3 py-2 text-center font-mono" dir="ltr">{{ c.code === base ? '1' : (c.current_rate ? `1 ${c.code} = ${Number(c.current_rate).toFixed(4)} ${base}` : '—') }}</td>
                            <td class="px-3 py-2 text-center"><button v-if="c.code !== base && $can('admin.settings.edit')" @click="remove(c)" class="text-rose-600 font-bold">{{ $t('set2_settings_currencies.delete') }}</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="$can('admin.settings.edit')" class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                    <h3 class="font-black text-slate-700">{{ $t('set2_settings_currencies.exchange_rate_on_date') }}</h3>
                    <p class="text-slate-500">{{ $t('set2_settings_currencies.a_document_takes_the_latest_rate') }}</p>
                    <Field :label="$t('set2_settings_currencies.currency')" :error="rate.errors.currency_code"><select v-model="rate.currency_code" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="c in foreign()" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name_ar }}</option></select></Field>
                    <Field :label="$t('set2_settings_currencies.date')" :error="rate.errors.date"><DatePicker v-model="rate.date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('set2_settings_currencies.rate_1_n_n', { n0: rate.currency_code || '...', n1: base })" :error="rate.errors.rate"><input v-model.number="rate.rate" type="text" inputmode="decimal" data-num step="0.000001" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <button @click="addRate" :disabled="rate.processing || !foreign().length" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50">{{ $t('set2_settings_currencies.save_the_rate') }}</button>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                    <h3 class="font-black text-slate-700">{{ $t('set2_settings_currencies.add_a_currency') }}</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <Field :label="$t('set2_settings_currencies.code_iso')" :error="currency.errors.code"><input v-model="currency.code" maxlength="3" dir="ltr" placeholder="USD" class="w-full rounded-xl border-slate-200 text-xs font-mono uppercase" /></Field>
                        <Field :label="$t('set2_settings_currencies.default_rate')" :error="currency.errors.exchange_rate"><input v-model.number="currency.exchange_rate" type="text" inputmode="decimal" data-num step="0.000001" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                        <Field :label="$t('set2_settings_currencies.name')" :error="currency.errors.name_ar" class="col-span-2"><input v-model="currency.name_ar" :placeholder="$t('set2_settings_currencies.us_dollar')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                        <Field :label="$t('set2_settings_currencies.short_symbol')"><input v-model="currency.symbol_ar" placeholder="$" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                        <Field :label="$t('set2_settings_currencies.name_in_english')"><input v-model="currency.name_en" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    </div>
                    <button @click="addCurrency" :disabled="currency.processing" class="w-full px-3 py-2 rounded-xl border border-slate-300 font-bold">{{ $t('set2_settings_currencies.add_the_currency') }}</button>
                </div>
            </div>
        </div>

        <div class="mt-4 bg-white border border-slate-200 rounded-2xl overflow-hidden text-xs">
            <h3 class="px-4 py-3 font-black border-b border-slate-100">{{ $t('set2_settings_currencies.exchange_rate_history') }}</h3>
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('set2_settings_currencies.date') }}</th><th class="px-3 py-2">{{ $t('set2_settings_currencies.currency') }}</th><th class="px-3 py-2">{{ $t('set2_settings_currencies.price') }}</th></tr></thead>
                <tbody>
                    <tr v-for="h in history" :key="h.id" class="border-t border-slate-100"><td class="px-3 py-2 font-mono">{{ String(h.date).substring(0, 10) }}</td><td class="px-3 py-2 text-center font-mono">{{ h.currency_code }}</td><td class="px-3 py-2 text-center font-mono">{{ Number(h.rate).toFixed(6) }}</td></tr>
                    <tr v-if="!history.length"><td colspan="3" class="px-3 py-6 text-center text-slate-400">{{ $t('set2_settings_currencies.no_rates_recorded_yet_each_curre') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

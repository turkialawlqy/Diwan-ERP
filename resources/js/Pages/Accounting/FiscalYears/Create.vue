<script setup>
import { onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ periodTypes: Object, defaults: Object });

const form = useForm({ ...props.defaults });
useUnsavedGuard(form);

const preview = ref({ periods: [], overlap: null, months: 0 });
const error = ref('');
let timer = null;

const refresh = async () => {
    error.value = '';
    if (!form.start_date || !form.end_date) return;
    try {
        const { data } = await axios.get(route('accounting.fiscal-years.preview'), { params: { start_date: form.start_date, end_date: form.end_date, period_type: form.period_type } });
        preview.value = data;
    } catch (e) {
        preview.value = { periods: [], overlap: null, months: 0 };
        error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || '';
    }
};
watch(() => [form.start_date, form.end_date, form.period_type], () => { clearTimeout(timer); timer = setTimeout(refresh, 200); });
onMounted(refresh);

const submit = () => form.post(route('accounting.fiscal-years.store'));
const input = 'w-full rounded-xl border-slate-200 text-sm';
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_year_form.add_a_new_fiscal_year')" />
        <PageHeader :title="$t('a_year_form.add_a_new_fiscal_year')" :crumbs="[{ label: 'المحاسبة' }, { label: 'السنوات المالية', href: route('accounting.fiscal-years.index') }, { label: 'جديدة' }]" />

        <form @submit.prevent="submit" class="space-y-5">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <Field :label="$t('a_year_form.fiscal_year_name')" :error="form.errors.name"><input v-model="form.name" required maxlength="100" :class="input" /></Field>
                <Field :label="$t('a_year_form.period_type')" :error="form.errors.period_type">
                    <select v-model="form.period_type" :class="input"><option v-for="(label, key) in periodTypes" :key="key" :value="key">{{ label }}</option></select>
                </Field>
                <Field :label="$t('a_year_form.start_date')" :error="form.errors.start_date"><DatePicker v-model="form.start_date" required :class="input" /></Field>
                <Field :label="$t('a_year_form.end_date')" :error="form.errors.end_date"><DatePicker v-model="form.end_date" required :min="form.start_date" :class="input" /></Field>
                <p v-if="preview.overlap || form.errors.start_date" class="md:col-span-2 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs" data-overlap>
                    {{ form.errors.start_date || $t('a_year_form.overlap', { name: preview.overlap.name, from: preview.overlap.from, to: preview.overlap.to }) }}
                </p>
                <p v-if="error" class="md:col-span-2 text-rose-600 text-xs">{{ error }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5" data-period-preview>
                <h3 class="font-black text-slate-800 text-sm mb-1">{{ $t('a_year_form.preview', { n: preview.periods.length }) }}</h3>
                <p class="text-xs text-slate-400 mb-3">{{ $t('a_year_form.each_period_s_dates_are_derived') }}</p>
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 w-10 text-start">#</th><th class="px-3 py-2 text-start">{{ $t('a_year_form.period') }}</th><th class="px-3 py-2 text-start">{{ $t('a_year_form.from') }}</th><th class="px-3 py-2 text-start">{{ $t('a_year_form.to') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="p in preview.periods" :key="p.code" class="border-t border-slate-100">
                                <td class="px-3 py-2 font-mono font-bold text-indigo-600">{{ String(p.n).padStart(2, '0') }}</td>
                                <td class="px-3 py-2 font-bold">{{ p.label }}</td>
                                <td class="px-3 py-2 font-mono text-slate-500">{{ p.from }}</td>
                                <td class="px-3 py-2 font-mono text-slate-500">{{ p.to }}</td>
                            </tr>
                            <tr v-if="!preview.periods.length"><td colspan="4" class="py-6 text-center text-slate-400">{{ $t('a_year_form.set_the_start_and_end_dates') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" :disabled="form.processing || !!preview.overlap || !preview.periods.length" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold disabled:opacity-40">{{ $t('a_year_form.add_the_fiscal_year') }}</button>
                <Link :href="route('accounting.fiscal-years.index')" class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-sm font-bold bg-white">{{ $t('a_year_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { money } from '@/utils/format';

const props = defineProps({ accounts: Array, filters: Object, preview: Object, previewError: String });
const form = useForm({ account_ids: [...props.filters.account_ids].map(Number), period_from: props.filters.period_from, period_to: props.filters.period_to, basis: props.filters.basis, amount: props.filters.amount || '', notes: '' });
useUnsavedGuard(form);

const chosen = computed(() => props.accounts.filter((a) => form.account_ids.includes(a.id)));
const pick = (id) => { if (id && !form.account_ids.includes(Number(id))) form.account_ids.push(Number(id)); };
const drop = (id) => { form.account_ids = form.account_ids.filter((x) => x !== id); };
const runPreview = () => router.get(route('projects.allocations.create'), { preview: 1, account_ids: form.account_ids, period_from: form.period_from, period_to: form.period_to, basis: form.basis, amount: form.amount || undefined }, { preserveState: true, preserveScroll: true });
const post = () => form.post(route('projects.allocations.store'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('alloc.title')" />
        <PageHeader :title="$t('alloc.title')" :subtitle="$t('alloc.hint')" :crumbs="[{ label: $t('nav.projects') }, { label: $t('alloc.title'), href: route('projects.allocations.index') }, { label: $t('alloc.new') }]" />

        <form @submit.prevent="post" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 text-xs mb-4" data-allocation-form>
            <div>
                <div class="font-bold text-slate-600 mb-1">{{ $t('alloc.accounts') }}</div>
                <div class="flex flex-wrap gap-2 mb-2">
                    <span v-for="a in chosen" :key="a.id" class="inline-flex items-center gap-1 rounded-lg bg-indigo-50 text-indigo-700 px-2 py-1 font-bold">{{ a.code }} - {{ a.name_ar }} <button type="button" @click="drop(a.id)" class="text-indigo-400 hover:text-rose-600">×</button></span>
                </div>
                <div class="max-w-md"><SelectCreate :model-value="''" :options="accounts" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" :placeholder="$t('alloc.add_account')" @update:modelValue="pick" /></div>
                <p v-if="form.errors.account_ids" class="text-rose-600 mt-1">{{ form.errors.account_ids }}</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('alloc.from')"><DatePicker v-model="form.period_from" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('alloc.to')"><DatePicker v-model="form.period_to" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('alloc.basis')">
                    <select v-model="form.basis" class="w-full rounded-xl border-slate-200 text-xs"><option value="revenue">{{ $t('alloc.b_revenue') }}</option><option value="direct_cost">{{ $t('alloc.b_direct_cost') }}</option><option value="hours">{{ $t('alloc.b_hours') }}</option></select>
                </Field>
                <Field :label="$t('alloc.amount')" :error="form.errors.amount"><input v-model="form.amount" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <Field :label="$t('alloc.notes')"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <p v-if="form.basis === 'hours'" class="text-amber-700 font-bold">{{ $t('alloc.hours_note') }}</p>
            <div class="flex gap-2">
                <button type="button" @click="runPreview" :disabled="!form.account_ids.length" class="px-4 py-2 rounded-xl border border-indigo-200 text-indigo-700 font-bold" data-allocation-preview>{{ $t('alloc.preview') }}</button>
                <button type="submit" :disabled="form.processing || !preview" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-40" data-allocation-post>{{ $t('alloc.post') }}</button>
            </div>
            <p v-for="(msg, key) in form.errors" :key="key" v-show="!['account_ids', 'amount'].includes(key)" class="text-rose-600">{{ msg }}</p>
        </form>

        <p v-if="previewError" class="text-xs text-rose-600 font-bold mb-3" data-allocation-error>{{ previewError }}</p>
        <div v-if="preview" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-allocation-preview-table>
            <div class="px-4 py-3 border-b border-slate-100 text-xs flex flex-wrap gap-x-6 gap-y-1">
                <span>{{ $t('alloc.available') }}: <b class="font-mono">{{ money(preview.available) }}</b></span>
                <span>{{ $t('alloc.pool_amount') }}: <b class="font-mono">{{ money(preview.pool) }}</b></span>
                <span v-for="w in preview.warnings" :key="w" class="text-amber-700 font-bold">{{ w }}</span>
            </div>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('alloc.project') }}</th><th class="text-end px-3 py-2">{{ $t('alloc.basis_value') }}</th><th class="text-end px-3 py-2">{{ $t('alloc.share') }}</th><th class="text-end px-3 py-2">{{ $t('alloc.allocated') }}</th></tr></thead>
                <tbody>
                    <tr v-for="l in preview.lines" :key="l.project_id" class="border-t border-slate-100"><td class="px-3 py-2">{{ l.code }} - {{ l.name }}</td><td class="px-3 py-2 text-end font-mono">{{ money(l.basis_value) }}</td><td class="px-3 py-2 text-end font-mono">{{ Number(l.share_pct).toFixed(2) }}</td><td class="px-3 py-2 text-end font-mono font-bold">{{ money(l.amount) }}</td></tr>
                </tbody>
                <tfoot class="bg-slate-100 font-black"><tr><td class="px-3 py-2">{{ $t('alloc.total') }}</td><td class="px-3 py-2 text-end font-mono">{{ money(preview.basis_total) }}</td><td class="px-3 py-2 text-end font-mono">100</td><td class="px-3 py-2 text-end font-mono">{{ money(preview.pool) }}</td></tr></tfoot>
            </table>
            <div class="px-4 py-3 border-t border-slate-100 text-xs"><div class="font-bold text-slate-600 mb-1">{{ $t('alloc.credits') }}</div><div v-for="c in preview.accounts" :key="c.account_id" class="flex justify-between max-w-md"><span>{{ c.name }}</span><span class="font-mono">{{ money(c.amount) }}</span></div></div>
        </div>
    </AppLayout>
</template>

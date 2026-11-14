<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, today } from '@/utils/format';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ templates: Array, accounts: Array, costCenters: Array });
const open = ref(false);
const blank = () => ({ account_id: '', cost_center_id: '', debit: 0, credit: 0, description: '' });
const form = useForm({ name: '', description: '', frequency: 'monthly', next_run_date: today(), end_date: '', auto_reverse: false, lines: [blank(), blank()] });
const totals = computed(() => form.lines.reduce((t, l) => ({ d: t.d + Number(l.debit || 0), c: t.c + Number(l.credit || 0) }), { d: 0, c: 0 }));
const balanced = computed(() => Math.abs(totals.value.d - totals.value.c) < 0.01 && totals.value.d > 0);
const save = () => form.post(route('accounting.recurring.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
const toggle = (t) => router.put(route('accounting.recurring.update', t.id), { is_active: !t.is_active }, { preserveScroll: true });
const remove = (t) => confirm(t('a_recurring.confirm_delete')) && router.delete(route('accounting.recurring.destroy', t.id), { preserveScroll: true });
const freq = computed(() => ({ monthly: t('s_recurring.f_monthly'), quarterly: t('s_recurring.f_quarterly'), yearly: t('s_recurring.f_yearly') }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_recurring.recurring_entries')" />
        <PageHeader :title="$t('a_recurring.recurring_entries')" :subtitle="$t('a_recurring.entries_posted_automatically_on')" :crumbs="[{ label: 'المحاسبة' }, { label: 'القيود المتكررة' }]">
            <template #actions><button v-if="$can('accounting.recurring.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('a_recurring.recurring_entry') }}</button></template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('ui.name') }}</th><th class="px-4 py-2">{{ $t('a_recurring.frequency') }}</th><th class="px-4 py-2">{{ $t('a_recurring.next_run') }}</th><th class="text-end px-4 py-2">{{ $t('ui.amount') }}</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-semibold">{{ t.name }} <span v-if="t.auto_reverse" class="text-3xs text-indigo-600 font-bold">{{ $t('a_recurring.reverses_automatically') }}</span><p class="text-slate-400 font-normal">{{ t.description }}</p><p v-if="t.last_error" class="text-rose-600 font-normal text-3xs">تعذّر الترحيل: {{ t.last_error }}</p></td>
                        <td class="px-4 py-2 text-center">{{ freq[t.frequency] }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(t.next_run_date) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ money(t.lines.reduce((s, l) => s + Number(l.debit || 0), 0)) }}</td>
                        <td class="px-4 py-2 text-center"><button v-if="$can('accounting.recurring.edit')" @click="toggle(t)" :class="t.is_active ? 'text-emerald-600' : 'text-slate-400'" class="font-bold">{{ t.is_active ? $t('s_common.active_m') : $t('a_cheques.suspended') }}</button></td>
                        <td class="px-4 py-2 text-end"><button v-if="$can('accounting.recurring.delete')" @click="remove(t)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                    </tr>
                    <tr v-if="!templates.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
        </div>

        <Dialog :show="open" :title="$t('a_recurring.new_recurring_entry')" size="lg" @close="open = false">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs mb-3">
                <Field :label="$t('a_recurring.name')" :error="form.errors.name"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="flex items-center gap-2 text-xs col-span-full"><input v-model="form.auto_reverse" type="checkbox" class="rounded" /> {{ $t('a_recurring.accrual_entry_reverses_automatic') }}</label>
                <Field :label="$t('a_recurring.frequency')"><select v-model="form.frequency" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in freq" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="$t('a_recurring.first_run')"><DatePicker v-model="form.next_run_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('a_recurring.ends_on_optional')"><DatePicker v-model="form.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="col-span-2 md:col-span-4"><Field :label="$t('a_recurring.entry_description')" :error="form.errors.description"><input v-model="form.description" class="w-full rounded-xl border-slate-200 text-xs" /></Field></div>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr v-for="(l, i) in form.lines" :key="i">
                    <td class="p-1"><SelectCreate v-model="l.account_id" :options="accounts" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" :allow-empty="false" /></td>
                    <td class="p-1 w-36"><SelectCreate v-model="l.cost_center_id" :options="costCenters" entity="cost_center" label="name_ar" :placeholder="$t('a_recurring.cost_center')" /></td>
                    <td class="p-1 w-28"><input v-model.number="l.debit" @focus="l.credit = 0" type="text" inputmode="decimal" data-num step="0.01" :placeholder="$t('a_recurring.debit')" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="p-1 w-28"><input v-model.number="l.credit" @focus="l.debit = 0" type="text" inputmode="decimal" data-num step="0.01" :placeholder="$t('a_recurring.credit')" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="p-1 w-8"><button v-if="form.lines.length > 2" @click="form.lines.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                </tr>
            </table></div>
            <div class="flex items-center justify-between mt-2 text-xs">
                <button @click="form.lines.push(blank())" class="text-indigo-600 font-bold">{{ $t('a_recurring.line') }}</button>
                <span :class="balanced ? 'text-emerald-600' : 'text-rose-600'" class="font-mono font-bold">{{ money(totals.d) }} / {{ money(totals.c) }}</span>
            </div>
            <p v-if="form.errors.lines" class="text-xs text-rose-600 mt-2">{{ form.errors.lines }}</p>
            <template #footer><button @click="save" :disabled="form.processing || !balanced" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

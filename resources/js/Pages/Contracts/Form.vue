<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today } from '@/utils/format';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ contract: Object, templates: Array, suppliers: Array, projects: Array, kinds: Object, obligationKinds: Object, customerId: Number });
const c = props.contract;
const editing = c?.id;
const d = (v) => (v ? String(v).slice(0, 10) : '');
const form = useForm({
    title: c?.title || '', kind: c?.kind || 'service', party_type: c?.party_type || 'customer',
    customer_id: c?.customer_id || props.customerId || null, supplier_id: c?.supplier_id || '', template_id: c?.template_id || '', project_id: c?.project_id || '',
    start_date: d(c?.start_date) || today(), end_date: d(c?.end_date), value: c?.value ?? '', payment_terms: c?.payment_terms || '',
    auto_renew: !!c?.auto_renew, renewal_term_months: c?.renewal_term_months || '', renewal_notice_days: c?.renewal_notice_days ?? 30,
    body: c?.body || '', version_note: '', attachments: c?.attachments || [],
    obligations: (c?.obligations || []).map((o) => ({ id: o.id, title: o.title, kind: o.kind, due_date: d(o.due_date), amount: o.amount ?? '', notes: o.notes || '', done: o.status === 'done' })),
});
useUnsavedGuard(form);

const created = ref([]); // templates made from the dropdown on this page
const allTemplates = computed(() => [...props.templates, ...created.value]);
const usable = computed(() => allTemplates.value.filter((t) => t.kind === form.kind || t.kind === 'other'));
const template = ref('');
const applyTemplate = (id) => {
    template.value = id;
    const tpl = allTemplates.value.find((x) => String(x.id) === String(id));
    if (tpl && (!form.body || window.confirm(t('con2_contracts_form.replace_the_contract_text_with_t')))) { form.body = tpl.body; form.template_id = tpl.id; }
};
const addObligation = () => form.obligations.push({ id: null, title: '', kind: 'payment', due_date: form.start_date, amount: '', notes: '', done: false });

const uploading = ref(false);
const upload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    uploading.value = true;
    const body = new FormData();
    body.append('file', file);
    try {
        const { data } = await window.axios.post(route('contracts.attachments.upload'), body);
        form.attachments.push({ name: data.file.name, url: data.file.url });
    } finally { uploading.value = false; e.target.value = ''; }
};
const submit = () => (editing ? form.put(route('contracts.update', editing)) : form.post(route('contracts.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('con2_contracts_form.edit_n', { n0: c.number }) : $t('con2_contracts_form.new_contract')" />
        <PageHeader :title="editing ? $t('con2_contracts_form.edit_contract_n', { n0: c.number }) : $t('con2_contracts_form.new_contract')" :crumbs="[{ label: $t('con2_contracts_form.contracts'), href: route('contracts.index') }, { label: editing ? $t('con2_contracts_form.edit') : $t('con2_contracts_form.new') }]" />
        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <Field :label="$t('con2_contracts_form.contract_title')" :error="form.errors.title" class="md:col-span-2"><input v-model="form.title" required maxlength="255" :class="input" data-title /></Field>
                <Field :label="$t('con2_contracts_form.type')" :error="form.errors.kind"><select v-model="form.kind" :class="input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="$t('con2_contracts_form.counterparty')"><select v-model="form.party_type" :class="input" data-party-type><option value="customer">{{ $t('con2_contracts_form.customers') }}</option><option value="supplier">{{ $t('con2_contracts_form.supplier') }}</option></select></Field>
                <Field v-if="form.party_type === 'customer'" :label="$t('con2_contracts_form.customer')" :error="form.errors.customer_id" class="md:col-span-2"><PartyPicker v-model="form.customer_id" :initial="c?.customer" /></Field>
                <Field v-else :label="$t('con2_contracts_form.supplier_2')" :error="form.errors.supplier_id" class="md:col-span-2"><SelectCreate v-model="form.supplier_id" :options="suppliers" entity="supplier" :label="(s) => s.name" :placeholder="$t('con2_contracts_form.choose_the_supplier')" /></Field>
                <Field :label="$t('con2_contracts_form.start_date')" :error="form.errors.start_date"><DatePicker v-model="form.start_date" :class="input" /></Field>
                <Field :label="$t('con2_contracts_form.end_date')" :error="form.errors.end_date" :hint="$t('con2_contracts_form.leave_empty_for_an_open_ended_co')"><DatePicker v-model="form.end_date" :class="input" /></Field>
                <Field :label="$t('con2_contracts_form.contract_value')" :error="form.errors.value" :hint="$t('con2_contracts_form.the_approval_rule_is_decided_by')"><input v-model="form.value" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
                <Field :label="$t('con2_contracts_form.linked_project')" :error="form.errors.project_id"><SelectCreate v-model="form.project_id" :options="projects" :label="(p) => `${p.code} - ${p.name}`" :placeholder="$t('con2_contracts_form.none')" /></Field>
                <Field :label="$t('con2_contracts_form.payment_terms')" :error="form.errors.payment_terms" class="md:col-span-2"><input v-model="form.payment_terms" maxlength="2000" :class="input" /></Field>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.auto_renew" class="rounded" data-auto-renew /> {{ $t('con2_contracts_form.renews_automatically') }}</label>
                <Field :label="$t('con2_contracts_form.renewal_term_months')" :error="form.errors.renewal_term_months"><input v-model="form.renewal_term_months" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
                <Field :label="$t('con2_contracts_form.alert_before_the_end_days')" :error="form.errors.renewal_notice_days"><input v-model="form.renewal_notice_days" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-bold text-slate-800">{{ $t('con2_contracts_form.contract_text') }}</h2>
                    <div class="w-56"><SelectCreate :model-value="template" @update:model-value="applyTemplate" @created="(t) => created.push(t)" :options="usable" entity="contract_template" :label="(t) => t.name" :placeholder="$t('con2_contracts_form.use_a_template')" data-template-select /></div>
                </div>
                <textarea v-model="form.body" rows="14" class="w-full rounded-xl border-slate-200 text-xs leading-relaxed" :placeholder="$t('con2_contracts_form.contract_clauses')" data-body></textarea>
                <Field v-if="editing" :label="$t('con2_contracts_form.note_on_the_change')" :hint="$t('con2_contracts_form.saved_with_the_new_version_when')"><input v-model="form.version_note" maxlength="255" :class="input" /></Field>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between"><h2 class="font-bold text-slate-800">{{ $t('con2_contracts_form.obligations_and_due_dates') }}</h2>
                    <button type="button" @click="addObligation" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-200 font-bold" data-add-obligation><PlusIcon class="w-4 h-4" /> {{ $t('con2_contracts_form.obligation') }}</button></div>
                <div v-for="(o, i) in form.obligations" :key="i" class="grid grid-cols-2 md:grid-cols-12 gap-2 items-start border-b border-slate-50 pb-2" :class="o.done ? 'opacity-60' : ''">
                    <input v-model="o.title" required :placeholder="$t('con2_contracts_form.title')" :class="input" class="md:col-span-4" :disabled="o.done" :data-ob-title="i" />
                    <select v-model="o.kind" :class="input" class="md:col-span-2" :disabled="o.done"><option v-for="(l, k) in obligationKinds" :key="k" :value="k">{{ l }}</option></select>
                    <DatePicker v-model="o.due_date" :class="input" class="md:col-span-2" />
                    <input v-model="o.amount" type="text" inputmode="decimal" data-num :placeholder="$t('con2_contracts_form.amount')" :class="input" class="md:col-span-2 font-mono" :disabled="o.done" />
                    <button type="button" class="md:col-span-1 p-2 text-slate-400 hover:text-rose-600" @click="form.obligations.splice(i, 1)" :disabled="o.done" :title="$t('con2_contracts_form.delete')"><TrashIcon class="w-4 h-4" /></button>
                    <p v-if="form.errors[`obligations.${i}.title`] || form.errors[`obligations.${i}.due_date`]" class="col-span-full text-rose-600">{{ form.errors[`obligations.${i}.title`] || form.errors[`obligations.${i}.due_date`] }}</p>
                </div>
                <p v-if="!form.obligations.length" class="text-slate-400">{{ $t('con2_contracts_form.no_obligations_yet_payments_deli') }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                <h2 class="font-bold text-slate-800">{{ $t('con2_contracts_form.attachments') }}</h2>
                <ul class="space-y-1"><li v-for="(a, i) in form.attachments" :key="i" class="flex items-center justify-between"><a :href="a.url" target="_blank" class="text-indigo-600 font-bold">{{ a.name }}</a><button type="button" class="text-rose-600" @click="form.attachments.splice(i, 1)">{{ $t('con2_contracts_form.remove') }}</button></li></ul>
                <input type="file" @change="upload" :disabled="uploading" class="text-xs" data-attach />
            </div>

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-contract>{{ editing ? $t('con2_contracts_form.save') : $t('con2_contracts_form.create_the_contract') }}</button>
                <Link :href="editing ? route('contracts.show', editing) : route('contracts.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('con2_contracts_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

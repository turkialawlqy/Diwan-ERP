<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { dateTime } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ lead: Object, users: Array, statuses: Object, sources: Object });
const l = props.lead;
const form = useForm({ name: l.name, company_name: l.company_name, phone: l.phone, email: l.email, source: l.source, expected_value: l.expected_value, next_follow_up: l.next_follow_up?.substring(0, 10) || '', assigned_to: l.assigned_to || '', notes: l.notes, status: l.status, lost_reason: l.lost_reason || '' });
const activity = useForm({ type: 'call', notes: '', next_follow_up: '' });
const types = computed(() => ({ call: t('x_crm_lead.call'), meeting: t('x_crm_lead.meeting'), email: t('x_crm_lead.email_2'), whatsapp: t('x_crm_lead.whatsapp'), note: t('x_crm_lead.note') }));
const inp = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="lead.name" />
        <PageHeader :title="lead.name" :subtitle="lead.company_name" :crumbs="[{ label: 'CRM' }, { label: $t('x_crm_lead.leads'), href: route('crm.leads.index') }, { label: lead.name }]">
            <template #actions>
                <Link v-if="lead.customer" :href="route('sales.clients.show', lead.customer.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('x_crm_lead.customer_file') }}</Link>
                <button v-else-if="$can('crm.leads.convert')" @click="router.post(route('crm.leads.convert', lead.id))" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('x_crm_lead.convert_to_a_customer') }}</button>
            </template>
        </PageHeader>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <Field :label="$t('x_crm_lead.stage')"><select v-model="form.status" :class="inp"><option v-for="(lb, k) in statuses" :key="k" :value="k">{{ lb }}</option></select></Field>
                <Field v-if="form.status === 'lost'" :label="$t('x_crm_lead.loss_reason')" :error="form.errors.lost_reason"><input v-model="form.lost_reason" :class="inp" /></Field>
                <Field :label="$t('x_crm_lead.name')"><input v-model="form.name" :class="inp" /></Field>
                <Field :label="$t('x_crm_lead.company')"><input v-model="form.company_name" :class="inp" /></Field>
                <div class="grid grid-cols-2 gap-2"><Field :label="$t('x_crm_lead.mobile')"><input v-model="form.phone" :class="inp" /></Field><Field :label="$t('x_crm_lead.email')"><input v-model="form.email" :class="inp" /></Field></div>
                <div class="grid grid-cols-2 gap-2"><Field :label="$t('x_crm_lead.expected_value')"><input v-model.number="form.expected_value" type="text" inputmode="decimal" data-num :class="inp" /></Field><Field :label="$t('x_crm_lead.follow_up')"><DatePicker v-model="form.next_follow_up" :class="inp" /></Field></div>
                <Field :label="$t('x_crm_lead.owner')"><select v-model="form.assigned_to" :class="inp"><option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
                <Field :label="$t('x_crm_lead.source')"><select v-model="form.source" :class="inp"><option v-for="(lb, k) in sources" :key="k" :value="k">{{ lb }}</option></select></Field>
                <Field :label="$t('x_crm_lead.notes')"><textarea v-model="form.notes" rows="3" :class="inp"></textarea></Field>
                <button v-if="$can('crm.leads.edit')" @click="form.put(route('crm.leads.update', lead.id), { preserveScroll: true })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('x_crm_lead.save') }}</button>
            </div>
            <div class="lg:col-span-2 space-y-3">
                <div v-if="$can('crm.leads.edit')" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-4 gap-2">
                    <select v-model="activity.type" class="rounded-xl border-slate-200 text-xs"><option v-for="(lb, k) in types" :key="k" :value="k">{{ lb }}</option></select>
                    <DatePicker v-model="activity.next_follow_up" :title="$t('x_crm_lead.next_follow_up_date')" class="rounded-xl border-slate-200 text-xs" />
                    <textarea v-model="activity.notes" rows="2" :placeholder="$t('x_crm_lead.what_happened')" class="col-span-4 rounded-xl border-slate-200 text-xs"></textarea>
                    <button @click="activity.post(route('crm.leads.activities.store', lead.id), { preserveScroll: true, onSuccess: () => activity.reset('notes') })" class="col-span-4 px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">{{ $t('x_crm_lead.log_an_activity') }}</button>
                </div>
                <div v-for="a in lead.activities" :key="a.id" class="bg-white border border-slate-200 rounded-xl p-3">
                    <div class="flex justify-between text-slate-500"><b class="text-slate-700">{{ types[a.type] }}</b><span>{{ a.user?.name }} · <span class="font-mono">{{ dateTime(a.happened_at) }}</span></span></div>
                    <p class="mt-1 whitespace-pre-line">{{ a.notes }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

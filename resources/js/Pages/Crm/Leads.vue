<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ board: Array, users: Array, sources: Object, filters: Object, stats: Object });
const open = ref(false);
const form = useForm({ name: '', company_name: '', phone: '', email: '', source: 'call', expected_value: 0, next_follow_up: '', assigned_to: '', notes: '' });
const dragging = ref(null);
const losing = ref(null);
const lostForm = useForm({ status: 'lost', lost_reason: '' });
const drop = (status) => {
    const lead = dragging.value;
    dragging.value = null;
    if (!lead || lead.status === status) return;
    if (status === 'lost') { // a lost lead needs its reason
        lostForm.reset();
        lostForm.clearErrors();
        losing.value = lead;
        return;
    }
    router.post(route('crm.leads.move', lead.id), { status }, { preserveScroll: true });
};
const confirmLost = () => lostForm.post(route('crm.leads.move', losing.value.id), { preserveScroll: true, onSuccess: () => { losing.value = null; } });
const overdue = (d) => d && new Date(d) <= new Date();
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_crm_leads.leads')" />
        <PageHeader :title="$t('x_crm_leads.leads_and_opportunities')" :subtitle="$t('x_crm_leads.drag_the_card_to_change_the_stag')" :crumbs="[{ label: 'CRM' }, { label: $t('x_crm_leads.leads') }]">
            <template #actions><button v-if="$can('crm.leads.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('x_crm_leads.lead') }}</button>
                <ImportButton v-if="$can('crm.leads.create')" type="leads" :title="$t('x_crm_leads.import_leads_from_excel')" /></template>
        </PageHeader>
        <div class="grid grid-cols-3 gap-3 mb-4">
            <StatCard :label="$t('x_crm_leads.value_of_open_opportunities')" :value="money(stats.open_value)" tone="indigo" />
            <StatCard :label="$t('x_crm_leads.won_this_month')" :value="stats.won_this_month" tone="emerald" />
            <StatCard :label="$t('x_crm_leads.follow_ups_due')" :value="stats.due_follow_ups" tone="amber" />
        </div>
        <div class="flex gap-3 overflow-x-auto pb-3 text-xs">
            <div v-for="col in board" :key="col.status" class="min-w-60 w-60 bg-slate-100 rounded-2xl p-2" @dragover.prevent @drop="drop(col.status)">
                <div class="flex justify-between px-1 mb-2"><b>{{ col.label }} ({{ col.leads.length }})</b><span class="font-mono text-slate-500">{{ money(col.value) }}</span></div>
                <Link v-for="l in col.leads" :key="l.id" :href="route('crm.leads.show', l.id)" draggable="true" @dragstart="dragging = l" class="block bg-white rounded-xl p-2 mb-2 border border-slate-200 hover:border-indigo-300">
                    <b>{{ l.name }}</b><div class="text-slate-500">{{ l.company_name }}</div>
                    <div class="flex justify-between mt-1"><span class="font-mono">{{ money(l.expected_value) }}</span><span :class="overdue(l.next_follow_up) ? 'text-rose-600 font-bold' : 'text-slate-400'" class="font-mono">{{ date(l.next_follow_up) }}</span></div>
                    <div class="text-slate-400">{{ l.assignee?.name }}</div>
                </Link>
            </div>
        </div>
        <Dialog :show="open" :title="$t('x_crm_leads.new_lead')" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field :label="$t('x_crm_leads.name')" :error="form.errors.name"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_crm_leads.company')"><input v-model="form.company_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_crm_leads.mobile')"><input v-model="form.phone" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_crm_leads.email')" :error="form.errors.email"><input v-model="form.email" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_crm_leads.source')"><select v-model="form.source" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in sources" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field :label="$t('x_crm_leads.expected_value')"><input v-model.number="form.expected_value" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('x_crm_leads.follow_up_date')"><DatePicker v-model="form.next_follow_up" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_crm_leads.owner')"><select v-model="form.assigned_to" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
                <Field :label="$t('x_crm_leads.notes')" class="col-span-2"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <template #footer><button @click="form.post(route('crm.leads.store'))" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
        <Dialog :show="!!losing" :title="$t('crm.lost_title')" size="sm" @close="losing = null">
            <Field :label="$t('crm.lost_reason')" :error="lostForm.errors.lost_reason"><input v-model="lostForm.lost_reason" maxlength="500" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <template #footer><button @click="confirmLost" :disabled="lostForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ categories: Array, agents: Array, channels: Object, priorities: Object, customerId: Number, subscriptionId: Number });
const form = useForm({
    customer_id: props.customerId || null, contact_name: '', contact_phone: '', contact_email: '',
    subject: '', description: '', channel: 'phone', priority: 'normal', category_id: '', assigned_to: '', subscription_id: props.subscriptionId || '',
});
useUnsavedGuard(form);
const submit = () => form.post(route('support.tickets.store'));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_support_tickets_form.new_ticket')" />
        <PageHeader :title="$t('x_support_tickets_form.new_support_ticket')" :crumbs="[{ label: $t('x_support_tickets_form.customer_service') }, { label: $t('x_support_tickets_form.tickets'), href: route('support.tickets.index') }, { label: $t('x_support_tickets_form.new') }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('x_support_tickets_form.customer')" :error="form.errors.customer_id" :hint="$t('x_support_tickets_form.or_type_the_caller_s_details_if')"><PartyPicker v-model="form.customer_id" /></Field>
            <Field :label="$t('x_support_tickets_form.caller_name')" :error="form.errors.contact_name"><input v-model="form.contact_name" :class="input" /></Field>
            <Field :label="$t('x_support_tickets_form.phone')" :error="form.errors.contact_phone"><input v-model="form.contact_phone" :class="input" /></Field>
            <Field :label="$t('x_support_tickets_form.email')" :error="form.errors.contact_email"><input v-model="form.contact_email" type="email" :class="input" /></Field>
            <Field :label="$t('x_support_tickets_form.subject')" :error="form.errors.subject" class="md:col-span-2"><input v-model="form.subject" required maxlength="255" :class="input" data-subject /></Field>
            <Field :label="$t('x_support_tickets_form.details')" :error="form.errors.description" class="md:col-span-2"><textarea v-model="form.description" rows="5" :class="input"></textarea></Field>
            <Field :label="$t('x_support_tickets_form.channel')" :error="form.errors.channel"><select v-model="form.channel" :class="input"><option v-for="(l, k) in channels" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field :label="$t('x_support_tickets_form.priority')" :error="form.errors.priority" :hint="$t('x_support_tickets_form.sets_the_first_response_and_reso')"><select v-model="form.priority" :class="input"><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field :label="$t('x_support_tickets_form.category')" :error="form.errors.category_id"><SelectCreate v-model="form.category_id" :options="categories" entity="support_category" :label="(c) => c.name" :placeholder="$t('x_support_tickets_form.none')" /></Field>
            <Field :label="$t('x_support_tickets_form.owner')" :error="form.errors.assigned_to"><SelectCreate v-model="form.assigned_to" :options="agents" :label="(a) => a.name" :placeholder="$t('x_support_tickets_form.unassigned')" /></Field>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-ticket>{{ $t('x_support_tickets_form.open_the_ticket') }}</button>
                <Link :href="route('support.tickets.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('x_support_tickets_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

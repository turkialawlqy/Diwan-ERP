<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { money, today } from '@/utils/format';

const props = defineProps({ plans: Array, cycles: Object, customerId: Number });
const form = useForm({ customer_id: props.customerId || null, service_plan_id: '', quantity: 1, unit_price: '', start_date: today(), auto_renew: true, auto_send: false, notes: '' });
useUnsavedGuard(form);
const plan = computed(() => props.plans.find((p) => String(p.id) === String(form.service_plan_id)));
const price = computed(() => (form.unit_price !== '' ? Number(form.unit_price) : Number(plan.value?.price || 0)));
const total = computed(() => price.value * (Number(form.quantity) || 0) * (1 + Number(plan.value?.tax_rate || 0) / 100));
const submit = () => form.post(route('sales.subscriptions.store'));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_sub_form.new_subscription')" />
        <PageHeader :title="$t('s_sub_form.new_subscription')" :crumbs="[{ label: 'المبيعات' }, { label: 'اشتراكات العملاء', href: route('sales.subscriptions.index') }, { label: 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('s_sub_form.customer')" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" /></Field>
            <Field :label="$t('s_sub_form.plan')" :error="form.errors.service_plan_id">
                <SelectCreate v-model="form.service_plan_id" :options="plans" entity="service_plan" :label="(p) => `${p.name} — ${money(p.price)} / ${cycles[p.cycle]}`" :placeholder="$t('s_sub_form.choose_the_plan')" required />
            </Field>
            <Field :label="$t('s_sub_form.quantity')" :error="form.errors.quantity" :hint="$t('s_sub_form.number_of_users_or_units')"><input v-model="form.quantity" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field :label="$t('s_sub_form.special_price_for_the_customer_o')" :error="form.errors.unit_price" :hint="$t('s_sub_form.leave_empty_for_the_plan_price')"><input v-model="form.unit_price" type="text" inputmode="decimal" data-num :class="input" class="font-mono" :placeholder="plan ? String(plan.price) : ''" /></Field>
            <Field :label="$t('s_sub_form.start_date')" :error="form.errors.start_date" :hint="plan?.trial_days ? $t('s_sub_form.trial_hint', { n: plan.trial_days }) : $t('s_sub_form.first_invoice_hint')"><DatePicker v-model="form.start_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <div class="bg-indigo-50 rounded-xl p-3 flex flex-col justify-center" data-subscription-total>
                <span class="text-slate-500">{{ $t('s_sub_form.value_of_each_period_including_t') }}</span>
                <span class="text-lg font-black font-mono text-indigo-700">{{ money(total) }}</span>
            </div>
            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.auto_renew" class="rounded" /> {{ $t('s_sub_form.renews_automatically') }}</label>
            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.auto_send" class="rounded" /> {{ $t('s_sub_form.email_every_invoice_to_the_custo') }}</label>
            <Field :label="$t('s_sub_form.notes')" :error="form.errors.notes" class="md:col-span-2"><input v-model="form.notes" maxlength="2000" :class="input" /></Field>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-subscription>{{ $t('s_sub_form.start_subscription') }}</button>
                <Link :href="route('sales.subscriptions.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('s_sub_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

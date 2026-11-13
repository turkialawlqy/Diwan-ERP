<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ plan: Object, cycles: Object, products: Array, defaultTax: Number });
const editing = props.plan?.id;
const form = useForm({
    name: props.plan?.name || '',
    description: props.plan?.description || '',
    price: props.plan?.price ?? '',
    tax_rate: props.plan?.tax_rate ?? props.defaultTax ?? 15,
    cycle: props.plan?.cycle || 'monthly',
    interval: props.plan?.interval || 1,
    trial_days: props.plan?.trial_days || 0,
    booking_discount_percent: props.plan?.booking_discount_percent || 0,
    is_membership: props.plan?.is_membership || false,
    product_id: props.plan?.product_id || '',
    is_active: props.plan ? !!props.plan.is_active : true,
});
useUnsavedGuard(form);
const submit = () => (editing ? form.put(route('sales.service-plans.update', editing)) : form.post(route('sales.service-plans.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('s_plan_form.edit') : $t('s_plan_form.new')" />
        <PageHeader :title="editing ? $t('s_plan_form.edit') : $t('s_plan_form.new')" :crumbs="[{ label: 'المبيعات' }, { label: 'خطط الاشتراكات', href: route('sales.service-plans.index') }, { label: editing ? 'تعديل' : 'جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('s_plan_form.plan_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" required :class="input" /></Field>
            <Field :label="$t('s_plan_form.price_per_period_before_tax')" :error="form.errors.price"><input v-model="form.price" required type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field :label="$t('s_plan_form.tax_rate')" :error="form.errors.tax_rate"><input v-model="form.tax_rate" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field :label="$t('s_plan_form.billing_cycle')" :error="form.errors.cycle">
                <select v-model="form.cycle" :class="input"><option v-for="(label, key) in cycles" :key="key" :value="key">{{ label }}</option></select>
            </Field>
            <Field :label="$t('s_plan_form.every_how_many_cycles')" :error="form.errors.interval" :hint="$t('s_plan_form.1_every_cycle_2_every_two_cycles')"><input v-model="form.interval" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field :label="$t('s_plan_form.free_trial_days')" :error="form.errors.trial_days" :hint="$t('s_plan_form.no_invoice_is_issued_before_it_e')"><input v-model="form.trial_days" type="text" inputmode="decimal" data-num :class="input" class="font-mono" /></Field>
            <Field :label="$t('s_plan_form.member_discount_on_bookings')" :error="form.errors.booking_discount_percent" :hint="$t('s_plan_form.the_subscriber_gets_it_on_all_th')"><input v-model="form.booking_discount_percent" type="text" inputmode="decimal" data-num :class="input" class="font-mono" data-booking-discount /></Field>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600"><input type="checkbox" v-model="form.is_membership" data-is-membership /> {{ $t('s_plan_form.membership_allows_entry_at_the_c') }}</label>
            <Field :label="$t('s_plan_form.service_item_on_the_invoice_opti')" :error="form.errors.product_id" :hint="$t('s_plan_form.a_service_item_from_inventory_sh')">
                <SelectCreate v-model="form.product_id" :options="products" entity="product" :label="(p) => p.name_ar" :placeholder="$t('s_plan_form.no_item')" />
            </Field>
            <Field :label="$t('s_plan_form.description')" :error="form.errors.description" class="md:col-span-2"><input v-model="form.description" maxlength="2000" :class="input" /></Field>
            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.is_active" class="rounded" /> {{ $t('s_plan_form.the_plan_is_active_sold_for_new') }}</label>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-plan>{{ editing ? $t('s_common.save') : $t('s_common.add') }}</button>
                <Link :href="route('sales.service-plans.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('s_plan_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

const props = defineProps({ plan: Object, assigned: Array, types: Object, users: Array });
const editing = props.plan?.id;
const p = props.plan;
const form = useForm({
    name: p?.name || '',
    type: p?.type || 'flat',
    rate: p?.rate ?? '',
    tiers: (p?.tiers || []).map((t) => ({ ...t })),
    target_amount: p?.target_amount ?? '',
    bonus_amount: p?.bonus_amount ?? '',
    is_active: p ? !!p.is_active : true,
    users: [...(props.assigned || [])],
});
useUnsavedGuard(form);
const num = (v) => (v === '' || v === null ? null : Number(v));
const submit = () => form.transform((d) => ({
    ...d, rate: num(d.rate), target_amount: num(d.target_amount), bonus_amount: num(d.bonus_amount),
    tiers: d.tiers.map((t) => ({ from: Number(t.from) || 0, rate: Number(t.rate) || 0 })),
})).submit(editing ? 'put' : 'post', editing ? route('sales.commission-plans.update', editing) : route('sales.commission-plans.store'));
const toggleUser = (id) => { form.users = form.users.includes(id) ? form.users.filter((u) => u !== id) : [...form.users, id]; };
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('s_comm_plan_form.edit') : $t('s_comm_plan_form.new')" />
        <PageHeader :title="editing ? $t('s_comm_plan_form.edit') : $t('s_comm_plan_form.new')" :crumbs="[{ label: 'المبيعات' }, { label: 'خطط العمولات', href: route('sales.commission-plans.index') }, { label: editing ? 'تعديل' : 'جديدة' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('s_comm_plan_form.plan_name')" :error="form.errors.name"><input v-model="form.name" required :class="input" /></Field>
            <Field :label="$t('s_comm_plan_form.commission_type')" :error="form.errors.type"><select v-model="form.type" :class="input"><option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option></select></Field>

            <Field v-if="form.type === 'flat'" :label="$t('s_comm_plan_form.rate_of_net_sales')" :error="form.errors.rate"><input v-model="form.rate" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <div v-else class="md:col-span-2 border border-slate-100 rounded-xl p-3 space-y-2">
                <div class="flex items-center justify-between"><b>{{ $t('s_comm_plan_form.tiers_each_rate_applies_to_the_p') }}</b><button type="button" @click="form.tiers.push({ from: '', rate: '' })" class="px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold">{{ $t('s_comm_plan_form.tier') }}</button></div>
                <div v-for="(t, i) in form.tiers" :key="i" class="grid grid-cols-12 gap-2 items-center">
                    <span class="col-span-2 text-slate-500">{{ $t('s_comm_plan_form.from_sales_of') }}</span>
                    <input v-model="t.from" type="text" inputmode="decimal" data-num :class="input" class="col-span-3 font-mono" />
                    <span class="col-span-2 text-center text-slate-500">{{ $t('s_comm_plan_form.rate') }}</span>
                    <input v-model="t.rate" type="text" inputmode="decimal" data-num :class="input" class="col-span-3 font-mono" />
                    <button type="button" @click="form.tiers.splice(i, 1)" class="col-span-2 text-rose-500 font-bold">{{ $t('s_comm_plan_form.delete') }}</button>
                </div>
                <p v-if="form.errors.tiers" class="text-rose-600">{{ form.errors.tiers }}</p>
            </div>

            <Field :label="$t('s_comm_plan_form.monthly_sales_target_optional')" :error="form.errors.target_amount"><input v-model="form.target_amount" type="text" inputmode="decimal" data-num :class="input" /></Field>
            <Field :label="$t('s_comm_plan_form.target_reached_bonus')" :error="form.errors.bonus_amount"><input v-model="form.bonus_amount" type="text" inputmode="decimal" data-num :class="input" /></Field>

            <div class="md:col-span-2">
                <div class="font-bold text-slate-700 mb-1.5">{{ $t('s_comm_plan_form.representatives_on_this_plan') }}</div>
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <label v-for="u in users" :key="u.id" class="flex items-center gap-1.5"><input type="checkbox" :checked="form.users.includes(u.id)" @change="toggleUser(u.id)" class="rounded" /> {{ u.name }}</label>
                </div>
            </div>
            <label class="flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.is_active" class="rounded" /> {{ $t('s_comm_plan_form.the_plan_is_active') }}</label>
            <div class="md:col-span-2 flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ editing ? $t('s_common.save') : $t('s_common.add') }}</button>
                <Link :href="route('sales.commission-plans.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('s_comm_plan_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

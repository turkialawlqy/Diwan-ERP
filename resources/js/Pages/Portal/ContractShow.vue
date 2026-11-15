<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, contract: Object, acceptances: Array, accepted: Boolean, canAccept: Boolean, defaultName: String });
const form = useForm({ name: props.defaultName || '', agree: false });
const accept = () => form.post(route('customer-portal.contracts.accept', { company: props.portal.company.id, contract: props.contract.id }), { preserveScroll: true });
</script>

<template>
    <Head :title="$t('por_portal_contractshow.contract_n', { n0: contract.number })" />
    <PortalLayout :portal="portal" active="contracts">
        <div class="mb-3"><h1 class="text-lg font-black text-slate-900">{{ contract.title }}</h1><p class="text-xs text-slate-500"><span class="font-mono">{{ contract.number }}</span> {{ $t('por_portal_contractshow.version_n', { n0: contract.version }) }} <span v-if="contract.value">{{ $t('por_portal_contractshow.value') }} <span class="font-mono">{{ money(contract.value) }}</span></span></p></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 text-sm whitespace-pre-line leading-7 mb-4" data-contract-body>{{ contract.body }}</div>
        <div v-if="contract.obligations?.length" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-4">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">{{ $t('por_portal_contractshow.obligations') }}</h2>
            <table class="w-full text-xs"><tbody><tr v-for="o in contract.obligations" :key="o.id" class="border-t border-slate-100"><td class="px-4 py-2 font-bold">{{ o.title }}</td><td class="px-4 py-2 font-mono">{{ o.due_date?.slice(0, 10) || '—' }}</td><td class="px-4 py-2 font-mono">{{ o.amount ? money(o.amount) : '' }}</td></tr></tbody></table>
        </div>
        <p v-if="accepted" class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs font-bold px-4 py-3 mb-3" data-accepted>{{ $t('por_portal_contractshow.you_have_already_accepted_this_v') }}</p>
        <form v-if="canAccept" @submit.prevent="accept" class="bg-white border border-amber-200 rounded-2xl p-4 space-y-3 text-xs" data-accept-form>
            <p class="font-bold text-amber-700">{{ $t('por_portal_contractshow.this_contract_awaits_your_accept') }}</p>
            <label class="block"><span class="font-bold">{{ $t('por_portal_contractshow.type_your_full_name_to_confirm') }}</span><input v-model="form.name" required class="mt-1 w-full rounded-xl border-slate-200 text-xs" data-accept-name /></label>
            <label class="flex items-center gap-2"><input v-model="form.agree" type="checkbox" class="rounded border-slate-300" data-accept-agree /> {{ $t('por_portal_contractshow.i_have_read_the_contract_and_agr') }}</label>
            <p v-if="form.errors.agree || form.errors.name" class="text-rose-600 font-bold">{{ form.errors.agree || form.errors.name }}</p>
            <button type="submit" :disabled="!form.agree || form.processing" class="px-5 py-2 rounded-xl bg-emerald-600 text-white font-bold disabled:opacity-40" data-accept-submit>{{ $t('por_portal_contractshow.i_agree') }}</button>
        </form>
        <div v-if="acceptances.length" class="mt-4 text-xs text-slate-500"><p v-for="(a, i) in acceptances" :key="i">{{ $t('por_portal_contractshow.version_n_was_accepted_by_n_on', { n0: a.version, n1: a.name }) }} <span class="font-mono">{{ a.accepted_at?.slice(0, 16).replace('T', ' ') }}</span></p></div>
    </PortalLayout>
</template>

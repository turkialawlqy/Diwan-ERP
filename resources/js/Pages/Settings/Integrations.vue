<script setup>
import { useI18n } from 'vue-i18n';
import { confirmAction } from '@/composables/useDialog';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ tokens: Array, abilities: Array, webhooks: Array, events: Object, newToken: String, newSecret: String, apiBase: String });
const tokenForm = useForm({ name: '', abilities: [...props.abilities], expires_in_days: 365 });
const hookForm = useForm({ url: '', events: [] });
const opts = { preserveScroll: true };
const remove = async (url, message) => { if (await confirmAction(message, { danger: true, confirmText: t('set2_settings_integrations.confirm') })) router.delete(url, opts); };
</script>

<template>
    <AppLayout>
        <Head :title="$t('set2_settings_integrations.api_integration')" />
        <PageHeader :title="$t('set2_settings_integrations.api_integration')" :subtitle="$t('set2_settings_integrations.api_tokens_for_your_other_system')" :crumbs="[{ label: $t('set2_settings_integrations.settings') }, { label: $t('set2_settings_integrations.api_integration') }]" />
        <div v-if="newToken" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs"><b>{{ $t('set2_settings_integrations.the_new_token_it_will_not_be_sho') }}</b><p class="font-mono break-all mt-1" dir="ltr">{{ newToken }}</p></div>
        <div v-if="newSecret" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs"><b>{{ $t('set2_settings_integrations.webhook_signing_key_it_will_not') }}</b><p class="font-mono break-all mt-1" dir="ltr">{{ newSecret }}</p></div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700">{{ $t('set2_settings_integrations.access_tokens_api') }}</h3>
                <p class="text-slate-500 mb-3" dir="ltr">Base URL: <span class="font-mono">{{ apiBase }}</span> — Header: <span class="font-mono">Authorization: Bearer &lt;token&gt;</span></p>
                <div v-for="t in tokens" :key="t.id" class="flex justify-between py-1.5 border-b border-slate-50">
                    <div><b>{{ t.name }}</b><div class="text-slate-400">{{ t.abilities.join($t('set2_settings_integrations.text')) }}</div><div class="text-slate-400">{{ $t('set2_settings_integrations.last_used_n_expires_n', { n0: t.last_used_at ? date(t.last_used_at) : '—', n1: t.expires_at ? date(t.expires_at) : 'بدون' }) }}</div></div>
                    <button @click="remove(route('integrations.tokens.destroy', t.id), $t('set2_settings_integrations.revoke_this_token_the_systems_us'))" class="text-rose-600 font-bold h-fit">{{ $t('set2_settings_integrations.cancel') }}</button>
                </div>
                <div class="space-y-2 mt-3">
                    <input v-model="tokenForm.name" :placeholder="$t('set2_settings_integrations.token_name_e_g_online_store')" class="w-full rounded-xl border-slate-200 text-xs" />
                    <div class="flex flex-wrap gap-2"><label v-for="a in abilities" :key="a" class="flex items-center gap-1 font-mono"><input v-model="tokenForm.abilities" :value="a" type="checkbox" class="rounded" /> {{ a }}</label></div>
                    <label class="block">{{ $t('set2_settings_integrations.validity_days') }}<input v-model.number="tokenForm.expires_in_days" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs" /></label>
                    <p v-for="(m, k) in tokenForm.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="tokenForm.post(route('integrations.tokens.store'), { ...opts, onSuccess: () => tokenForm.reset('name') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('set2_settings_integrations.create_a_token') }}</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700">Webhooks</h3>
                <p class="text-slate-500 mb-3">{{ $t('set2_settings_integrations.every_request_is_signed_with_hma') }}</p>
                <div v-for="w in webhooks" :key="w.id" class="py-1.5 border-b border-slate-50">
                    <div class="flex justify-between"><span class="font-mono break-all" dir="ltr">{{ w.url }}</span><span class="whitespace-nowrap"><button @click="router.post(route('webhooks.test', w.id), {}, opts)" class="text-indigo-600 font-bold me-2">{{ $t('set2_settings_integrations.test') }}</button><button @click="remove(route('webhooks.destroy', w.id), $t('set2_settings_integrations.delete_this_webhook'))" class="text-rose-600 font-bold">{{ $t('set2_settings_integrations.delete') }}</button></span></div>
                    <div class="text-slate-400">{{ $t('set2_settings_integrations.n_last_status_n_n', { n0: w.events.join('، '), n1: w.last_status || '—', n2: w.is_active ? '' : '(موقوف)' }) }}</div>
                </div>
                <div class="space-y-2 mt-3">
                    <input v-model="hookForm.url" placeholder="https://..." class="w-full rounded-xl border-slate-200 text-xs font-mono" dir="ltr" />
                    <div class="flex flex-wrap gap-2"><label v-for="(l, k) in events" :key="k" class="flex items-center gap-1"><input v-model="hookForm.events" :value="k" type="checkbox" class="rounded" /> {{ l }}</label></div>
                    <p v-for="(m, k) in hookForm.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="hookForm.post(route('webhooks.store'), { ...opts, onSuccess: () => hookForm.reset() })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('set2_settings_integrations.add') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

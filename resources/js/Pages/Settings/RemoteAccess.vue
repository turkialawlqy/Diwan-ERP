<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { useI18n } from 'vue-i18n';

const props = defineProps({ status: Object, active: Object, lan: { type: Array, default: () => [] } });
const { t } = useI18n();

const form = useForm({ mode: props.active?.mode || 'none', domain: '', secret: '' });
useUnsavedGuard(form);

const live = ref(props.status);
const activeNow = ref(props.active);
const busy = computed(() => ['requested', 'running'].includes(live.value?.state));
const modes = computed(() => [
    { key: 'none', title: t('remote.mode_none'), text: t('remote.mode_none_text') },
    { key: 'cloudflare', title: 'Cloudflare Tunnel', text: t('remote.mode_cloudflare_text') },
    { key: 'tailscale', title: 'Tailscale', text: t('remote.mode_tailscale_text') },
]);

// While the server applies the change (a minute or two) the status is refreshed every few seconds.
let timer = null;
const poll = async () => {
    try {
        const res = await fetch(route('settings.remote-access.status'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.ok) { const data = await res.json(); live.value = data.status; activeNow.value = data.active; }
    } catch { /* the service restarts while it applies: try again */ }
};
onMounted(() => { timer = setInterval(() => { if (busy.value) poll(); }, 3000); });
onBeforeUnmount(() => clearInterval(timer));

const save = () => form.post(route('settings.remote-access.apply'), {
    preserveScroll: true,
    onSuccess: () => { form.reset('secret'); live.value = { state: 'requested' }; },
});
const stateText = computed(() => ({
    requested: t('remote.state_requested'), running: t('remote.state_running'), done: t('remote.state_done'), failed: t('remote.state_failed'),
}[live.value?.state] || ''));
const input = 'w-full rounded-xl border-slate-200 text-xs';
const reload = () => router.reload();
</script>

<template>
    <AppLayout>
        <Head :title="$t('remote.title')" />
        <PageHeader :title="$t('remote.title')" :subtitle="$t('remote.subtitle')" :crumbs="[{ label: $t('remote.crumb') }, { label: $t('remote.title') }]" />

        <div class="space-y-5 text-xs">
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2" data-remote-status>
                <h3 class="font-black text-slate-700">{{ $t('remote.current') }}</h3>
                <p v-if="activeNow?.mode === 'none' || !activeNow">{{ $t('remote.current_none') }}</p>
                <template v-else>
                    <p class="font-bold">{{ activeNow.mode === 'cloudflare' ? 'Cloudflare Tunnel' : 'Tailscale' }}</p>
                    <p v-if="activeNow.url" class="font-mono text-base font-black text-indigo-700" dir="ltr">{{ activeNow.url }}</p>
                    <p v-if="activeNow.ip" class="font-mono text-slate-600" dir="ltr">Tailscale: {{ activeNow.ip }}</p>
                </template>
                <p v-if="lan.length" class="text-slate-500">{{ $t('remote.office_network') }}: <span class="font-mono" dir="ltr">{{ lan.join('  ') }}</span></p>
                <p v-if="live?.state" class="font-semibold" :class="live.state === 'failed' ? 'text-rose-600' : busy ? 'text-amber-600' : 'text-emerald-700'" data-remote-state>
                    {{ stateText }}<span v-if="live.message"> — {{ live.message }}</span>
                </p>
                <button v-if="live?.state === 'done'" type="button" class="underline text-slate-500" @click="reload">{{ $t('remote.refresh') }}</button>
            </section>

            <form class="space-y-5" @submit.prevent="save">
                <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                    <h3 class="font-black text-slate-700">{{ $t('remote.choose') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <label v-for="m in modes" :key="m.key" class="border rounded-xl p-3 cursor-pointer" :class="form.mode === m.key ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'" :data-remote-mode="m.key">
                            <input v-model="form.mode" type="radio" :value="m.key" class="me-2" />
                            <span class="font-bold">{{ m.title }}</span>
                            <p class="text-slate-500 mt-1 leading-relaxed">{{ m.text }}</p>
                        </label>
                    </div>
                    <p v-if="form.errors.mode" class="text-rose-600">{{ form.errors.mode }}</p>
                </section>

                <section v-if="form.mode === 'cloudflare'" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" data-remote-cloudflare>
                    <h3 class="font-black text-slate-700">Cloudflare Tunnel</h3>
                    <ol class="list-decimal ps-5 space-y-1 text-slate-600 leading-relaxed">
                        <li>{{ $t('remote.cf_step1') }}</li>
                        <li>{{ $t('remote.cf_step2') }}</li>
                        <li>{{ $t('remote.cf_step3') }}</li>
                    </ol>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <Field :label="$t('remote.domain')" :error="form.errors.domain"><input v-model="form.domain" :class="input" dir="ltr" placeholder="erp.example.com" autocomplete="off" data-remote-domain /></Field>
                        <Field :label="$t('remote.token')" :error="form.errors.secret"><input v-model="form.secret" type="password" :class="input" dir="ltr" autocomplete="new-password" data-remote-secret /></Field>
                    </div>
                </section>

                <section v-if="form.mode === 'tailscale'" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" data-remote-tailscale>
                    <h3 class="font-black text-slate-700">Tailscale</h3>
                    <ol class="list-decimal ps-5 space-y-1 text-slate-600 leading-relaxed">
                        <li>{{ $t('remote.ts_step1') }}</li>
                        <li>{{ $t('remote.ts_step2') }}</li>
                    </ol>
                    <Field :label="$t('remote.auth_key')" :error="form.errors.secret"><input v-model="form.secret" type="password" :class="input" dir="ltr" autocomplete="new-password" placeholder="tskey-auth-..." data-remote-secret /></Field>
                </section>

                <p class="text-slate-500 leading-relaxed">{{ $t('remote.secret_note') }}</p>
                <button type="submit" :disabled="form.processing || busy" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold hover:bg-indigo-700 disabled:opacity-50" data-remote-apply>{{ $t('remote.apply') }}</button>
            </form>
        </div>
    </AppLayout>
</template>

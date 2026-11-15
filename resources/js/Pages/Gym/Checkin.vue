<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

/** Reception: type or scan a member code; the answer (allowed / denied and why) shows at once. */
const props = defineProps({ today: Object });
const code = ref('');
const result = ref(null);
const day = ref(props.today);
const busy = ref(false);
const input = ref(null);
const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

const scan = async () => {
    if (!code.value.trim() || busy.value) return;
    busy.value = true;
    try {
        const res = await fetch(route('gym.checkin.scan'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() }, body: JSON.stringify({ code: code.value, method: 'scan' }) });
        const body = await res.json();
        result.value = body;
        day.value = body.today || day.value;
    } finally {
        busy.value = false;
        code.value = '';
        input.value?.focus();
    }
};
onMounted(() => input.value?.focus());
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_gym_checkin.record_members_attendance')" />
        <PageHeader :title="$t('x_gym_checkin.record_members_attendance')" :subtitle="$t('x_gym_checkin.scan_the_member_code_or_type_the')" :crumbs="[{ label: $t('x_gym_checkin.club') }, { label: $t('x_gym_checkin.record_attendance') }]" />
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 space-y-3">
                <input ref="input" v-model="code" @keyup.enter="scan" :placeholder="$t('x_gym_checkin.member_code_c_00001_or_mobile')" class="w-full rounded-2xl border-slate-200 text-xl text-center py-4 font-mono" dir="ltr" autocomplete="off" data-checkin-code />
                <div v-if="result" class="rounded-2xl p-6 text-center border-2" :class="result.allowed ? 'bg-emerald-50 border-emerald-300' : 'bg-rose-50 border-rose-300'" data-result>
                    <p class="text-2xl font-black" :class="result.allowed ? 'text-emerald-700' : 'text-rose-700'" data-verdict>{{ result.allowed ? $t('x_gym_checkin.entry_allowed') : $t('x_gym_checkin.not_allowed') }}</p>
                    <p v-if="result.customer" class="text-lg font-bold mt-1">{{ result.customer.name }}</p>
                    <p v-if="result.reason" class="text-rose-700 font-bold mt-1" data-reason>{{ result.reason }}</p>
                    <p v-if="result.subscription" class="text-slate-600 mt-1">{{ result.subscription.plan }}<span v-if="result.subscription.renews_on"> {{ $t('x_gym_checkin.renewal_n', { n0: result.subscription.renews_on }) }}</span></p>
                    <p v-if="result.package" class="text-slate-600 mt-1">{{ $t('x_gym_checkin.n_remaining', { n0: result.package.name }) }} <b class="font-mono">{{ result.package.remaining }}</b> {{ $t('x_gym_checkin.visit') }}</p>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <p class="font-bold mb-2">{{ $t('x_gym_checkin.today') }} <span class="text-emerald-700" data-count-allowed>{{ day.allowed }}</span> {{ $t('x_gym_checkin.entries') }} <span class="text-rose-600">{{ day.denied }}</span> {{ $t('x_gym_checkin.rejected') }}</p>
                <div class="max-h-96 overflow-y-auto divide-y divide-slate-100">
                    <p v-for="r in day.rows" :key="r.id" class="py-1.5 flex justify-between gap-2"><span><span class="font-mono text-slate-400">{{ r.time }}</span> {{ r.name }}</span><span :class="r.allowed ? 'text-emerald-700' : 'text-rose-600'">{{ r.allowed ? '✓' : r.reason }}</span></p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

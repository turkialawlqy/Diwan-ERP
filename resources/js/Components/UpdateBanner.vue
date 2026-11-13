<script setup>
import { useI18n } from 'vue-i18n';
import { ref, computed, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowPathIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

// "New version available" banner for the owner of a local install. The update runs in the
// background (the system keeps working); this only starts it and follows its progress.
const page = usePage();
const info = computed(() => page.props.update);
const status = ref(info.value?.status || null);
const busy = ref(false);
const error = ref('');
let timer = null;
let startedAt = 0;

const state = computed(() => status.value?.state);
const working = computed(() => busy.value || ['requested', 'running'].includes(state.value));
// "Updated" is shown only by the page that watched the update finish, never after a reload or a later visit.
const finished = ref(false);
const show = computed(() => info.value && (info.value.available || working.value || finished.value));

const poll = async () => {
    try {
        const { data } = await axios.get(route('system.update.status'));
        status.value = data.status;
        if (data.status?.state === 'done' || (!data.available && data.current !== info.value.current)) {
            clearInterval(timer);
            finished.value = true;
            setTimeout(() => window.location.reload(), 1500);
        } else if (data.status?.state === 'failed') {
            clearInterval(timer);
            busy.value = false;
        } else if (Date.now() - startedAt > 120000 && data.status?.state === 'requested') {
            clearInterval(timer);
            busy.value = false;
            error.value = t('cmp.upd_not_started');
        }
    } catch (e) {
        // The system restarts for a moment while switching versions: keep waiting.
    }
};

const start = async () => {
    busy.value = true;
    error.value = '';
    startedAt = Date.now();
    try {
        const { data } = await axios.post(route('system.update.apply'));
        status.value = data.status;
        timer = setInterval(poll, 3000);
    } catch (e) {
        busy.value = false;
        error.value = e.response?.data?.message || t('cmp.upd_failed_start');
    }
};

if (['requested', 'running'].includes(status.value?.state)) {
    startedAt = Date.now();
    timer = setInterval(poll, 3000);
}
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div v-if="show" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 flex flex-wrap items-center gap-3 print:hidden" data-update-banner>
        <ArrowDownTrayIcon v-if="!working" class="w-6 h-6 text-emerald-700 flex-shrink-0" />
        <ArrowPathIcon v-else class="w-6 h-6 text-emerald-700 flex-shrink-0 animate-spin" />
        <div class="flex-1 min-w-0 text-sm">
            <template v-if="finished">
                <b class="text-emerald-800">{{ $t('cmp.upd_done', { v: status?.to || info.latest?.version }) }}</b> {{ $t('cmp.reloading_the_page') }}
            </template>
            <template v-else-if="working">
                <b class="text-emerald-800">{{ $t('cmp.upd_working') }}{{ status?.to ? ' ' + status.to : '' }}</b>
                <span class="text-slate-600"> {{ $t('cmp.you_can_keep_working_the_system') }}</span>
            </template>
            <template v-else>
                <b class="text-emerald-800">{{ $t('cmp.upd_new', { v: info.latest?.version }) }}</b>
                <span class="text-slate-600"> {{ $t('cmp.upd_note', { v: info.current }) }}</span>
            </template>
            <p v-if="state === 'failed'" class="text-xs text-rose-700 mt-1">{{ $t('cmp.upd_last_failed') }}: {{ status.message }}</p>
            <p v-if="error" class="text-xs text-rose-700 mt-1">{{ error }}</p>
        </div>
        <button v-if="!working && info.available" type="button" @click="start" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition">{{ $t('cmp.update_now') }}</button>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ClockIcon } from '@heroicons/vue/24/outline';

/**
 * Signs the user out after the company's idle time (prop idleMinutes, 0 = off).
 * Activity in any open tab counts (shared through localStorage); a minute before
 * the end a dialog counts down with «متابعة الجلسة» and «تسجيل الخروج». While the
 * user works without reaching the server (typing a long form) the session is kept
 * alive every few minutes, so the server's own idle check agrees with the page.
 */
const page = usePage();
const minutes = computed(() => Number(page.props.idleMinutes) || 0);
const KEY = 'diwan.last_activity';
const WARN_SECONDS = 60;

const remaining = ref(0);
const warning = ref(false);
let timer = null;
let lastPing = Date.now();
let lastActivity = Date.now();

const readShared = () => {
    try { return Number(localStorage.getItem(KEY)) || 0; } catch { return 0; }
};
const writeShared = (t) => {
    try { localStorage.setItem(KEY, String(t)); } catch { /* storage unavailable */ }
};
const latest = () => Math.max(lastActivity, readShared());

const ping = () => {
    lastPing = Date.now();
    return axios.post(route('session.keep-alive')).catch(() => {});
};

const activity = () => {
    if (warning.value) return; // the dialog decides
    const now = Date.now();
    if (now - lastActivity < 5000) return;
    lastActivity = now;
    writeShared(now);
    // Work without server requests still counts on the server.
    if (now - lastPing > 4 * 60 * 1000) ping();
};

const logout = () => {
    clearInterval(timer);
    warning.value = false;
    router.post(route('logout'));
};

const stay = () => {
    lastActivity = Date.now();
    writeShared(lastActivity);
    warning.value = false;
    ping();
};

const tick = () => {
    if (!minutes.value) return;
    const left = Math.round((latest() + minutes.value * 60 * 1000 - Date.now()) / 1000);
    remaining.value = Math.max(0, left);
    if (left <= 0) return logout();
    warning.value = left <= WARN_SECONDS;
};

const EVENTS = ['mousedown', 'keydown', 'scroll', 'touchstart', 'mousemove'];
let offNavigate = null;
onMounted(() => {
    lastActivity = Date.now();
    writeShared(lastActivity);
    EVENTS.forEach((e) => window.addEventListener(e, activity, { passive: true }));
    // Every page visit reaches the server: it is activity on both sides.
    offNavigate = router.on('success', () => { lastPing = lastActivity = Date.now(); writeShared(lastActivity); });
    timer = setInterval(tick, 1000);
});
onBeforeUnmount(() => {
    EVENTS.forEach((e) => window.removeEventListener(e, activity));
    offNavigate?.();
    clearInterval(timer);
});

const clock = computed(() => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`);
</script>

<template>
    <div v-if="warning" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4" data-idle-warning>
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center space-y-4" role="alertdialog" aria-live="assertive">
            <div class="mx-auto w-12 h-12 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center"><ClockIcon class="w-7 h-7" /></div>
            <h2 class="text-base font-black text-slate-900">{{ $t('shared.idle_title') }}</h2>
            <p class="text-sm text-slate-600">{{ $t('shared.idle_body') }}</p>
            <p class="text-3xl font-black font-mono text-rose-600" data-idle-countdown>{{ clock }}</p>
            <div class="flex gap-2 justify-center">
                <button type="button" @click="stay" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold" data-idle-stay>{{ $t('shared.idle_stay') }}</button>
                <button type="button" @click="logout" class="px-5 py-2 rounded-xl border border-slate-200 text-slate-600 text-sm font-bold">{{ $t('shared.logout') }}</button>
            </div>
        </div>
    </div>
</template>

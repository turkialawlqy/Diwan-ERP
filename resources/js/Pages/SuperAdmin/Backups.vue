<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { dateTime } from '@/utils/format';

const props = defineProps({ runs: Array, files: Array, config: Object });
const flash = computed(() => usePage().props.flash || {});
const lastOk = computed(() => props.runs.find((r) => r.type === 'backup' && r.status === 'ok'));
const lastVerify = computed(() => props.runs.find((r) => r.type === 'verify'));
const mb = (b) => (b == null ? '—' : (b / 1048576).toFixed(2) + ' MB');
const run = (verify) => router.post(route('super-admin.backups.run'), { verify }, { preserveScroll: true });
const ageHours = computed(() => (lastOk.value ? Math.round((Date.now() - new Date(lastOk.value.created_at)) / 36e5) : null));
</script>

<template>
    <SuperAdminLayout>
        <Head :title="$t('z_superadmin_backups.backups')" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 space-y-4 text-xs">
            <div v-if="flash.success" class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 font-bold text-emerald-800">{{ flash.success }}</div>
            <div v-if="flash.error" class="rounded-xl bg-rose-50 border border-rose-200 p-3 font-bold text-rose-800">{{ flash.error }}</div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">{{ $t('z_superadmin_backups.last_successful_backup') }}</div>
                    <div class="text-base font-black mt-1" :class="ageHours === null || ageHours > 26 ? 'text-rose-600' : 'text-slate-800'">{{ lastOk ? dateTime(lastOk.created_at) : $t('z_superadmin_backups.none') }}</div>
                    <div v-if="ageHours !== null && ageHours > 26" class="text-rose-600 mt-1">{{ $t('z_superadmin_backups.older_than_a_day_check_the_sched') }}</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">{{ $t('z_superadmin_backups.last_restore_test') }}</div>
                    <div class="text-base font-black mt-1" :class="lastVerify?.status === 'ok' ? 'text-emerald-700' : 'text-rose-600'">{{ lastVerify ? (lastVerify.status === 'ok' ? $t('z_superadmin_backups.successful') : $t('z_superadmin_backups.failed')) + ' · ' + dateTime(lastVerify.created_at) : $t('z_superadmin_backups.not_run_yet') }}</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">{{ $t('z_superadmin_backups.encryption') }}</div>
                    <div class="text-base font-black mt-1" :class="config.encrypted ? 'text-emerald-700' : 'text-rose-600'">{{ config.encrypted ? $t('z_superadmin_backups.aes_256_enabled') : $t('z_superadmin_backups.not_enabled_set_backup_password') }}</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">{{ $t('z_superadmin_backups.storage_and_retention') }}</div>
                    <div class="font-bold mt-1">{{ $t('z_superadmin_backups.disk_n_daily_n_weekly_n_monthly', { n0: config.disk, n1: config.keep.daily, n2: config.keep.weekly, n3: config.keep.monthly }) }}</div>
                    <div class="text-slate-400 mt-1">{{ config.notify ? $t('z_superadmin_backups.failure_alert_by_email_is_on') : $t('z_superadmin_backups.no_alert_email_backup_notify_ema') }}</div>
                </div>
            </div>

            <div class="flex gap-2">
                <button @click="run(false)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('z_superadmin_backups.back_up_now') }}</button>
                <button @click="run(true)" class="px-4 py-2 rounded-xl border border-slate-300 bg-white font-bold">{{ $t('z_superadmin_backups.test_restoring_the_latest_backup') }}</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-2xl border overflow-hidden">
                    <h3 class="px-4 py-3 font-black border-b">{{ $t('z_superadmin_backups.operations_log') }}</h3>
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">{{ $t('z_superadmin_backups.time') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_backups.type') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_backups.status') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_backups.size') }}</th><th class="px-3 py-2 text-start">{{ $t('z_superadmin_backups.details') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="r in runs" :key="r.id" class="border-t">
                                <td class="px-3 py-2 font-mono">{{ dateTime(r.created_at) }}</td>
                                <td class="px-3 py-2 text-center">{{ r.type === 'verify' ? $t('z_superadmin_backups.restore_test') : $t('z_superadmin_backups.backup') }}</td>
                                <td class="px-3 py-2 text-center font-bold" :class="r.status === 'ok' ? 'text-emerald-700' : 'text-rose-600'">{{ r.status === 'ok' ? $t('z_superadmin_backups.successful') : $t('z_superadmin_backups.failed') }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ mb(r.size) }}</td>
                                <td class="px-3 py-2 text-slate-500 break-all">{{ r.error || (r.details ? $t('z_superadmin_backups.n_tables_n_recordsn', { n0: r.details.tables, n1: r.details.rows, n2: r.details.method ? ' · ' + r.details.method : '' }) : '') }}</td>
                            </tr>
                            <tr v-if="!runs.length"><td colspan="5" class="px-3 py-6 text-center text-slate-400">{{ $t('z_superadmin_backups.no_operations_yet') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="bg-white rounded-2xl border overflow-hidden">
                    <h3 class="px-4 py-3 font-black border-b">{{ $t('z_superadmin_backups.saved_files_n', { n0: files.length }) }}</h3>
                    <ul class="divide-y">
                        <li v-for="f in files" :key="f.file" class="px-4 py-2 flex justify-between"><span class="font-mono" dir="ltr">{{ f.file }}</span><span class="font-mono text-slate-500">{{ mb(f.size) }}</span></li>
                        <li v-if="!files.length" class="px-4 py-6 text-center text-slate-400">{{ $t('z_superadmin_backups.no_files') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </SuperAdminLayout>
</template>

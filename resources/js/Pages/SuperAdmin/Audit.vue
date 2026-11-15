<script setup>
import { Head } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { dateTime } from '@/utils/format';

defineProps({ logs: Object });
</script>

<template>
    <SuperAdminLayout>
        <Head :title="$t('z_superadmin_audit.platform_audit_log')" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 text-xs">
            <div class="bg-white rounded-2xl border overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">{{ $t('z_superadmin_audit.time') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_audit.user') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_audit.company') }}</th><th class="px-3 py-2">{{ $t('z_superadmin_audit.action') }}</th><th class="text-start px-3 py-2">{{ $t('z_superadmin_audit.details') }}</th><th class="px-3 py-2">IP</th></tr></thead>
                    <tbody><tr v-for="l in logs.data" :key="l.id" class="border-t"><td class="px-3 py-2 font-mono">{{ dateTime(l.created_at) }}</td><td class="px-3 py-2 text-center">{{ l.user?.name }}</td><td class="px-3 py-2 text-center">{{ l.company?.name }}</td><td class="px-3 py-2 text-center font-bold">{{ l.action }}</td><td class="px-3 py-2 break-all text-slate-500">{{ JSON.stringify(l.details) }}</td><td class="px-3 py-2 font-mono">{{ l.ip }}</td></tr></tbody>
                </table>
                <Pagination :paginator="logs" />
            </div>
        </div>
    </SuperAdminLayout>
</template>

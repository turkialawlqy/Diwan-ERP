<script setup>
import { Head } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';

defineProps({ logs: Object });
</script>

<template>
    <SuperAdminLayout>
        <Head title="سجل تدقيق المنصة" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 text-xs">
            <div class="bg-white rounded-2xl border overflow-hidden">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">الوقت</th><th class="px-3 py-2">المستخدم</th><th class="px-3 py-2">المنشأة</th><th class="px-3 py-2">الإجراء</th><th class="text-start px-3 py-2">التفاصيل</th><th class="px-3 py-2">IP</th></tr></thead>
                    <tbody><tr v-for="l in logs.data" :key="l.id" class="border-t"><td class="px-3 py-2 font-mono">{{ String(l.created_at).substring(0, 16).replace('T', ' ') }}</td><td class="px-3 py-2 text-center">{{ l.user?.name }}</td><td class="px-3 py-2 text-center">{{ l.company?.name }}</td><td class="px-3 py-2 text-center font-bold">{{ l.action }}</td><td class="px-3 py-2 break-all text-slate-500">{{ JSON.stringify(l.details) }}</td><td class="px-3 py-2 font-mono">{{ l.ip }}</td></tr></tbody>
                </table>
                <Pagination :paginator="logs" />
            </div>
        </div>
    </SuperAdminLayout>
</template>

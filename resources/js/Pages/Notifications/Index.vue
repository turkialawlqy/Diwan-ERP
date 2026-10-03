<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';

defineProps({ notifications: Object });
const tone = { warning: 'border-amber-300', danger: 'border-rose-400', info: 'border-indigo-300' };
</script>

<template>
    <AppLayout>
        <Head title="الإشعارات" />
        <PageHeader title="الإشعارات" :crumbs="[{ label: 'الإشعارات' }]">
            <template #actions><button @click="router.post(route('notifications.read-all'))" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تحديد الكل كمقروء</button></template>
        </PageHeader>
        <div class="space-y-2 text-xs">
            <Link v-for="n in notifications.data" :key="n.id" :href="route('notifications.read', n.id)" class="block bg-white border-s-4 border rounded-xl p-3" :class="[tone[n.data.level] || tone.info, n.read_at ? 'opacity-60' : '']">
                <div class="flex justify-between"><b>{{ n.data.title }}</b><span class="text-slate-400 font-mono">{{ String(n.created_at).substring(0, 16).replace('T', ' ') }}</span></div>
                <p class="text-slate-600 mt-1">{{ n.data.body }}</p>
            </Link>
            <p v-if="!notifications.data.length" class="text-center text-slate-400 py-10">لا توجد إشعارات</p>
        </div>
        <Pagination :paginator="notifications" />
    </AppLayout>
</template>

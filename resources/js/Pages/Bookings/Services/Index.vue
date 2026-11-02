<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

defineProps({ services: Array });
</script>

<template>
    <AppLayout>
        <Head title="خدمات الحجز" />
        <PageHeader title="خدمات الحجز" subtitle="الخدمات التي يحجزها العملاء بمدتها وسعرها" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الخدمات' }]">
            <template #actions>
                <Link v-if="$can('bookings.services.create')" :href="route('bookings.services.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-service><PlusIcon class="w-4 h-4" /> خدمة جديدة</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الخدمة</th><th class="px-4 py-2 text-start">المدة</th><th class="px-4 py-2 text-start">السعر</th><th class="px-4 py-2 text-start">العربون</th><th class="px-4 py-2 text-start">الموارد</th><th class="px-4 py-2 text-start">من البوابة</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="s in services" :key="s.id" class="border-t border-slate-100" :data-service="s.name">
                        <td class="px-4 py-2 font-bold">{{ s.name }}</td><td class="px-4 py-2 font-mono">{{ s.duration_minutes }} د</td><td class="px-4 py-2 font-mono">{{ money(s.price) }}</td>
                        <td class="px-4 py-2 font-mono">{{ s.deposit_percent }}%</td><td class="px-4 py-2 font-mono">{{ s.resources_count }}</td><td class="px-4 py-2">{{ s.online_bookable ? 'نعم' : 'لا' }}</td>
                        <td class="px-4 py-2"><span :class="s.is_active ? 'text-emerald-700' : 'text-slate-400'" class="font-bold">{{ s.is_active ? 'نشطة' : 'معطّلة' }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :edit="$can('bookings.services.edit') ? route('bookings.services.edit', s.id) : null" :remove="$can('bookings.services.delete') ? route('bookings.services.destroy', s.id) : null" :confirm="`حذف الخدمة ${s.name}؟`" /></td>
                    </tr>
                    <tr v-if="!services.length"><td colspan="8" class="py-10 text-center text-slate-400">لا خدمات بعد.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

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
        <Head :title="$t('bk2_bookings_services_index.booking_services')" />
        <PageHeader :title="$t('bk2_bookings_services_index.booking_services')" :subtitle="$t('bk2_bookings_services_index.the_services_customers_book_with')" :crumbs="[{ label: $t('bk2_bookings_services_index.bookings'), href: route('bookings.calendar') }, { label: $t('bk2_bookings_services_index.services') }]">
            <template #actions>
                <Link v-if="$can('bookings.services.create')" :href="route('bookings.services.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-service><PlusIcon class="w-4 h-4" /> {{ $t('bk2_bookings_services_index.new_service') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.service') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.duration') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.price') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.deposit') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.resources') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.from_the_portal') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_services_index.status') }}</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="s in services" :key="s.id" class="border-t border-slate-100" :data-service="s.name">
                        <td class="px-4 py-2 font-bold">{{ s.name }}</td><td class="px-4 py-2 font-mono">{{ $t('bk2_bookings_services_index.n_min', { n0: s.duration_minutes }) }}</td><td class="px-4 py-2 font-mono">{{ money(s.price) }}</td>
                        <td class="px-4 py-2 font-mono">{{ s.deposit_percent }}%</td><td class="px-4 py-2 font-mono">{{ s.resources_count }}</td><td class="px-4 py-2">{{ s.online_bookable ? $t('bk2_bookings_services_index.yes') : $t('bk2_bookings_services_index.no') }}</td>
                        <td class="px-4 py-2"><span :class="s.is_active ? 'text-emerald-700' : 'text-slate-400'" class="font-bold">{{ s.is_active ? $t('bk2_bookings_services_index.active') : $t('bk2_bookings_services_index.disabled') }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :edit="$can('bookings.services.edit') ? route('bookings.services.edit', s.id) : null" :remove="$can('bookings.services.delete') ? route('bookings.services.destroy', s.id) : null" :confirm="$t('bk2_bookings_services_index.delete_the_service_n', { n0: s.name })" /></td>
                    </tr>
                    <tr v-if="!services.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ $t('bk2_bookings_services_index.no_services_yet') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

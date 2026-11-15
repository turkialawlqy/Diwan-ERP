<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ resources: Array, types: Object });
</script>

<template>
    <AppLayout>
        <Head :title="$t('bk2_bookings_resources_index.booking_resources')" />
        <PageHeader :title="$t('bk2_bookings_resources_index.booking_resources')" :subtitle="$t('bk2_bookings_resources_index.employees_rooms_equipment_and_co')" :crumbs="[{ label: $t('bk2_bookings_resources_index.bookings'), href: route('bookings.calendar') }, { label: $t('bk2_bookings_resources_index.resources') }]">
            <template #actions>
                <Link v-if="$can('bookings.resources.create')" :href="route('bookings.resources.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-resource><PlusIcon class="w-4 h-4" /> {{ $t('bk2_bookings_resources_index.new_resource') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_resources_index.supplier') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_resources_index.type') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_resources_index.capacity') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_resources_index.upcoming_bookings') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_resources_index.status') }}</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="r in resources" :key="r.id" class="border-t border-slate-100" :data-resource="r.name">
                        <td class="px-4 py-2 font-bold">{{ r.name }}</td><td class="px-4 py-2">{{ types[r.type] }}</td><td class="px-4 py-2 font-mono">{{ r.capacity }}</td><td class="px-4 py-2 font-mono">{{ r.upcoming }}</td>
                        <td class="px-4 py-2"><span :class="r.is_active ? 'text-emerald-700' : 'text-slate-400'" class="font-bold">{{ r.is_active ? $t('bk2_bookings_resources_index.active') : $t('bk2_bookings_resources_index.disabled') }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :edit="$can('bookings.resources.edit') ? route('bookings.resources.edit', r.id) : null" :remove="$can('bookings.resources.delete') ? route('bookings.resources.destroy', r.id) : null" :confirm="$t('bk2_bookings_resources_index.delete_the_resource_n', { n0: r.name })" /></td>
                    </tr>
                    <tr v-if="!resources.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('bk2_bookings_resources_index.no_resources_yet') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

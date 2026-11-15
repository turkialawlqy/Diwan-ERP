<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({ portal: Object, upcoming: Array, past: Array, statuses: Object });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
const when = (v) => String(v).replace('T', ' ').slice(0, 16);
</script>

<template>
    <Head :title="$t('por_portal_bookings.my_bookings')" />
    <PortalLayout :portal="portal" active="bookings">
        <div class="flex items-center justify-between mb-3"><h1 class="text-lg font-black text-slate-900">{{ $t('por_portal_bookings.my_bookings') }}</h1>
            <Link :href="r('customer-portal.bookings.create')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-booking>{{ $t('por_portal_bookings.book_an_appointment') }}</Link></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-4">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">{{ $t('por_portal_bookings.upcoming') }}</h2>
            <table class="w-full text-xs"><tbody>
                <tr v-for="b in upcoming" :key="b.id" class="border-t border-slate-100" :data-booking="b.number">
                    <td class="px-4 py-2"><Link :href="r('customer-portal.bookings.show', { booking: b.id })" class="font-mono font-bold text-indigo-600">{{ b.number }}</Link></td>
                    <td class="px-4 py-2 font-mono">{{ when(b.starts_at) }}</td><td class="px-4 py-2">{{ b.service?.name }}</td><td class="px-4 py-2">{{ statuses[b.status] }}</td>
                </tr>
                <tr v-if="!upcoming.length"><td class="py-6 text-center text-slate-400">{{ $t('por_portal_bookings.no_upcoming_appointments') }}</td></tr>
            </tbody></table>
        </div>
        <div v-if="past.length" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">{{ $t('por_portal_bookings.past') }}</h2>
            <table class="w-full text-xs"><tbody>
                <tr v-for="b in past" :key="b.id" class="border-t border-slate-100">
                    <td class="px-4 py-2"><Link :href="r('customer-portal.bookings.show', { booking: b.id })" class="font-mono text-slate-600">{{ b.number }}</Link></td>
                    <td class="px-4 py-2 font-mono">{{ when(b.starts_at) }}</td><td class="px-4 py-2">{{ b.service?.name }}</td><td class="px-4 py-2">{{ statuses[b.status] }}</td>
                </tr>
            </tbody></table>
        </div>
    </PortalLayout>
</template>

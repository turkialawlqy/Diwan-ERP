<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { CalendarDaysIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ bookings: Object, filters: Object, statuses: Object, resources: Array, stats: Object });
const f = reactive({ ...props.filters });
const go = () => router.get(route('bookings.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const tone = { pending: 'text-amber-700', confirmed: 'text-sky-700', completed: 'text-emerald-700', cancelled: 'text-rose-600', no_show: 'text-slate-500' };
const when = (v) => String(v).replace('T', ' ').slice(0, 16);
</script>

<template>
    <AppLayout>
        <Head :title="$t('bk2_bookings_index.bookings')" />
        <PageHeader :title="$t('bk2_bookings_index.bookings')" :subtitle="$t('bk2_bookings_index.all_booked_appointments')" :crumbs="[{ label: $t('bk2_bookings_index.bookings') }, { label: $t('bk2_bookings_index.list') }]">
            <template #actions>
                <Link :href="route('bookings.calendar')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600"><CalendarDaysIcon class="w-4 h-4" /> {{ $t('bk2_bookings_index.calendar') }}</Link>
                <Link v-if="$can('bookings.bookings.create')" :href="route('bookings.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-booking><PlusIcon class="w-4 h-4" /> {{ $t('bk2_bookings_index.new_booking') }}</Link>
            </template>
        </PageHeader>
        <div class="grid grid-cols-2 gap-3 mb-4"><StatCard :label="$t('bk2_bookings_index.today_s_appointments')" :value="stats.today" /><StatCard :label="$t('bk2_bookings_index.awaiting_confirmation')" :value="stats.pending" /></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap items-end gap-2 text-xs">
            <input v-model="f.search" @keyup.enter="go" :placeholder="$t('bk2_bookings_index.booking_number_or_customer')" class="rounded-xl border-slate-200 text-xs w-48" />
            <select v-model="f.status" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('bk2_bookings_index.all_statuses') }}</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.resource" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('bk2_bookings_index.all_resources') }}</option><option v-for="r in resources" :key="r.id" :value="r.id">{{ r.name }}</option></select>
            <DatePicker v-model="f.from" @change="go" /><DatePicker v-model="f.to" @change="go" />
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_index.number') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_index.appointment') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_index.customer') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_index.service') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_index.supplier') }}</th><th class="px-4 py-2 text-start">{{ $t('bk2_bookings_index.status') }}</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="b in bookings.data" :key="b.id" class="border-t border-slate-100" :data-booking="b.number">
                        <td class="px-4 py-2 font-mono font-bold text-indigo-600">{{ b.number }}</td><td class="px-4 py-2 font-mono">{{ when(b.starts_at) }}</td>
                        <td class="px-4 py-2 font-bold">{{ b.customer?.name }}</td><td class="px-4 py-2">{{ b.service?.name }}</td><td class="px-4 py-2">{{ b.resource?.name }}</td>
                        <td class="px-4 py-2 font-bold" :class="tone[b.status]">{{ statuses[b.status] }}</td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('bookings.show', b.id)" :edit="['pending', 'confirmed'].includes(b.status) && $can('bookings.bookings.edit') ? route('bookings.edit', b.id) : null" /></td>
                    </tr>
                    <tr v-if="!bookings.data.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('bk2_bookings_index.no_bookings') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="bookings" />
    </AppLayout>
</template>

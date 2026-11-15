<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ vehicles: Object, filters: Object });
const search = ref(props.filters.search || '');
const due = ref(!!props.filters.due);
const go = () => router.get(route('workshop.vehicles.index'), { ...(search.value ? { search: search.value } : {}), ...(due.value ? { due: 1 } : {}) }, { preserveState: true, replace: true });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_workshop_vehicles_index.customers_vehicles')" />
        <PageHeader :title="$t('x_workshop_vehicles_index.customers_vehicles')" :subtitle="$t('x_workshop_vehicles_index.each_car_s_card_and_its_next_ser')" :crumbs="[{ label: $t('x_workshop_vehicles_index.workshop') }, { label: $t('x_workshop_vehicles_index.vehicles') }]">
            <template #actions><Link v-if="$can('workshop.vehicles.create')" :href="route('workshop.vehicles.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-vehicle><PlusIcon class="w-4 h-4" /> {{ $t('x_workshop_vehicles_index.new_vehicle') }}</Link></template>
        </PageHeader>
        <div class="mb-3 flex flex-wrap items-center gap-3 text-xs"><input v-model="search" @keyup.enter="go" :placeholder="$t('x_workshop_vehicles_index.plate_chassis_or_customer')" class="w-64 rounded-xl border-slate-200 text-xs" /><label class="flex items-center gap-1.5"><input v-model="due" type="checkbox" class="rounded border-slate-300" @change="go" /> {{ $t('x_workshop_vehicles_index.service_due_within_30_days') }}</label></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('x_workshop_vehicles_index.board') }}</th><th class="px-4 py-2 text-start">{{ $t('x_workshop_vehicles_index.vehicle') }}</th><th class="px-4 py-2 text-start">{{ $t('x_workshop_vehicles_index.customer') }}</th><th class="px-4 py-2 text-start">{{ $t('x_workshop_vehicles_index.meter') }}</th><th class="px-4 py-2 text-start">{{ $t('x_workshop_vehicles_index.next_service') }}</th><th class="px-4 py-2 w-16"></th></tr></thead>
            <tbody>
                <tr v-for="v in vehicles.data" :key="v.id" class="border-t border-slate-100" :data-vehicle="v.plate">
                    <td class="px-4 py-2"><Link :href="route('workshop.vehicles.show', v.id)" class="font-mono font-bold text-indigo-600">{{ v.plate }}</Link></td><td class="px-4 py-2">{{ [v.make, v.model, v.year].filter(Boolean).join(' ') }}</td>
                    <td class="px-4 py-2 font-bold">{{ v.customer?.name }}</td><td class="px-4 py-2 font-mono">{{ v.odometer }}</td><td class="px-4 py-2 font-mono">{{ d(v.next_service_date) }}</td>
                    <td class="px-4 py-2 text-end"><RowActions :view="route('workshop.vehicles.show', v.id)" :edit="$can('workshop.vehicles.edit') ? route('workshop.vehicles.edit', v.id) : null" :remove="$can('workshop.vehicles.delete') ? route('workshop.vehicles.destroy', v.id) : null" :confirm="$t('x_workshop_vehicles_index.delete_the_vehicle_n', { n0: v.plate })" /></td>
                </tr>
                <tr v-if="!vehicles.data.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('x_workshop_vehicles_index.no_vehicles') }}</td></tr>
            </tbody></table></div>
        <Pagination :paginator="vehicles" />
    </AppLayout>
</template>

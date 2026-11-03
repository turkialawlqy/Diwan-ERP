<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

defineProps({ property: Object, units: Array, types: Object, unitTypes: Object, statuses: Object });
const tone = { vacant: 'border-amber-300 bg-amber-50', occupied: 'border-emerald-300 bg-emerald-50', maintenance: 'border-slate-300 bg-slate-100' };
</script>

<template>
    <AppLayout>
        <Head :title="property.name" />
        <PageHeader :title="property.name" :subtitle="`${types[property.type]}${property.address ? ' — ' + property.address : ''}`" :crumbs="[{ label: 'العقارات', href: route('real-estate.properties.index') }, { label: property.name }]">
            <template #actions><Link v-if="$can('real_estate.properties.edit')" :href="route('real-estate.properties.edit', property.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">تعديل العقار</Link>
                <Link v-if="$can('real_estate.units.create')" :href="route('real-estate.units.create', { property: property.id })" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-unit><PlusIcon class="w-4 h-4" /> وحدة جديدة</Link></template>
        </PageHeader>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs" data-units>
            <div v-for="u in units" :key="u.id" class="rounded-2xl border p-4 space-y-1" :class="tone[u.status]" :data-unit="u.unit_number">
                <div class="flex items-center justify-between"><b class="text-sm">{{ u.unit_number }}</b><span class="font-bold">{{ statuses[u.status] }}</span></div>
                <p class="text-slate-600">{{ unitTypes[u.type] }}<span v-if="u.area"> · {{ Number(u.area) }} م²</span></p><p class="font-mono">{{ money(u.rent_amount) }}</p>
                <p v-if="u.tenant" class="font-bold">{{ u.tenant }}<span class="block font-normal text-slate-500">حتى {{ u.lease_end }}</span></p>
                <div class="flex items-center gap-2 pt-1">
                    <Link v-if="u.status === 'vacant' && $can('real_estate.leases.create')" :href="route('real-estate.leases.create', { unit: u.id })" class="text-indigo-700 font-bold" data-rent-unit>تأجير</Link>
                    <Link v-if="u.lease_id" :href="route('real-estate.leases.show', u.lease_id)" class="text-indigo-700 font-bold">العقد</Link>
                    <RowActions :edit="$can('real_estate.units.edit') ? route('real-estate.units.edit', u.id) : null" :remove="$can('real_estate.units.delete') && u.status !== 'occupied' ? route('real-estate.units.destroy', u.id) : null" :confirm="`حذف الوحدة ${u.unit_number}؟`" />
                </div>
            </div>
            <p v-if="!units.length" class="col-span-full text-center text-slate-400 py-10">لا وحدات بعد.</p>
        </div>
    </AppLayout>
</template>

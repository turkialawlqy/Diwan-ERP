<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ properties: Array, types: Object, totals: Object });
</script>

<template>
    <AppLayout>
        <Head title="العقارات والوحدات" />
        <PageHeader title="العقارات والوحدات" :crumbs="[{ label: 'العقارات' }, { label: 'العقارات والوحدات' }]">
            <template #actions><Link v-if="$can('real_estate.properties.create')" :href="route('real-estate.properties.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-property><PlusIcon class="w-4 h-4" /> عقار جديد</Link></template>
        </PageHeader>
        <div class="grid grid-cols-3 gap-3 mb-4"><StatCard label="الوحدات" :value="totals.units" /><StatCard label="مؤجَّرة" :value="totals.occupied" /><StatCard label="شاغرة" :value="totals.vacant" /></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">العقار</th><th class="px-4 py-2 text-start">النوع</th><th class="px-4 py-2 text-start">الوحدات</th><th class="px-4 py-2 text-start">مؤجَّرة</th><th class="px-4 py-2 text-start">شاغرة</th><th class="px-4 py-2 w-16"></th></tr></thead>
            <tbody>
                <tr v-for="p in properties" :key="p.id" class="border-t border-slate-100" :data-property="p.name">
                    <td class="px-4 py-2"><Link :href="route('real-estate.properties.show', p.id)" class="font-bold text-indigo-600">{{ p.name }}</Link><span v-if="p.address" class="block text-slate-500">{{ p.address }}</span></td><td class="px-4 py-2">{{ types[p.type] }}</td>
                    <td class="px-4 py-2 font-mono">{{ p.units_count }}</td><td class="px-4 py-2 font-mono text-emerald-700">{{ p.occupied_count }}</td><td class="px-4 py-2 font-mono text-amber-700">{{ p.vacant_count }}</td>
                    <td class="px-4 py-2 text-end"><RowActions :view="route('real-estate.properties.show', p.id)" :edit="$can('real_estate.properties.edit') ? route('real-estate.properties.edit', p.id) : null" :remove="$can('real_estate.properties.delete') ? route('real-estate.properties.destroy', p.id) : null" :confirm="`حذف العقار ${p.name}؟`" /></td>
                </tr>
                <tr v-if="!properties.length"><td colspan="6" class="py-10 text-center text-slate-400">لا عقارات بعد.</td></tr>
            </tbody></table></div>
    </AppLayout>
</template>

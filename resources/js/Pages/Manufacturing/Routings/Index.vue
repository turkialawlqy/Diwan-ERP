<script setup>
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ routings: Array });
const remove = (r) => confirm(t('mfg_manufacturing_routings_index.delete_the_routing_n', { n0: r.name })) && router.delete(route('manufacturing.routings.destroy', r.id), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_routings_index.routings')" />
        <PageHeader :title="$t('mfg_manufacturing_routings_index.routings')" :subtitle="$t('mfg_manufacturing_routings_index.operation_sequence_work_centers')" :crumbs="[{ label: $t('mfg_manufacturing_routings_index.manufacturing') }, { label: $t('mfg_manufacturing_routings_index.routings') }]">
            <template #actions>
                <Link v-if="$can('manufacturing.routings.create')" :href="route('manufacturing.routings.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_routings_index.new_routing') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_routings_index.code') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_routings_index.name') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_routings_index.product') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_routings_index.operations') }}</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in routings" :key="r.id" class="hover:bg-slate-50/60" :class="{ 'opacity-50': !r.is_active }">
                        <td class="px-4 py-3 font-mono font-bold">{{ r.code }}</td><td class="px-4 py-3 font-bold text-slate-800">{{ r.name }}</td><td class="px-4 py-3">{{ r.product?.name_ar || '—' }}</td><td class="px-4 py-3">{{ r.operations_count }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <Link v-if="$can('manufacturing.routings.edit')" :href="route('manufacturing.routings.edit', r.id)" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50"><PencilSquareIcon class="w-4 h-4" /></Link>
                            <button v-if="$can('manufacturing.routings.delete')" type="button" @click="remove(r)" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50"><TrashIcon class="w-4 h-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!routings.length"><td colspan="5" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_routings_index.no_routings_yet') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

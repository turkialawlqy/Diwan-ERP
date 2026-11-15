<script setup>
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ plans: Array });
const remove = (p) => confirm(t('mfg_manufacturing_quality_plans_index.delete_the_plan_n', { n0: p.name })) && router.delete(route('manufacturing.plans.destroy', p.id), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_quality_plans_index.quality_plans')" />
        <PageHeader :title="$t('mfg_manufacturing_quality_plans_index.quality_plans')" :subtitle="$t('mfg_manufacturing_quality_plans_index.the_inspection_items_a_product_i')" :crumbs="[{ label: $t('mfg_manufacturing_quality_plans_index.manufacturing') }, { label: $t('mfg_manufacturing_quality_plans_index.quality_plans') }]">
            <template #actions><Link v-if="$can('manufacturing.quality.create')" :href="route('manufacturing.plans.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_quality_plans_index.new_plan') }}</Link></template>
        </PageHeader>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_plans_index.plan') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_plans_index.product') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_plans_index.applies_to') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_quality_plans_index.items') }}</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="p in plans" :key="p.id" :class="{ 'opacity-50': !p.is_active }"><td class="px-4 py-3 font-bold text-slate-800">{{ p.name }}</td><td class="px-4 py-3">{{ p.product?.name_ar || $t('mfg_manufacturing_quality_plans_index.all_products') }}</td><td class="px-4 py-3">{{ p.applies_to === 'production' ? $t('mfg_manufacturing_quality_plans_index.production_receipt') : $t('mfg_manufacturing_quality_plans_index.purchase_receipt') }}</td><td class="px-4 py-3">{{ (p.checks || []).length }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <Link v-if="$can('manufacturing.quality.edit')" :href="route('manufacturing.plans.edit', p.id)" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50"><PencilSquareIcon class="w-4 h-4" /></Link>
                            <button v-if="$can('manufacturing.quality.delete')" type="button" @click="remove(p)" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50"><TrashIcon class="w-4 h-4" /></button></td></tr>
                    <tr v-if="!plans.length"><td colspan="5" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_quality_plans_index.no_plans_without_a_plan_producti') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

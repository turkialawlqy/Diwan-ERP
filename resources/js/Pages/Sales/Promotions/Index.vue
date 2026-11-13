<script setup>
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';
import { PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ promotions: Array, types: Object, documents: Object, filters: Object });
const setStatus = (e) => router.get(route('sales.promotions.index'), { status: e.target.value }, { preserveState: true, replace: true });
const remove = (p) => { if (confirm(t('s_promos.confirm_delete', { name: p.name }))) router.delete(route('sales.promotions.destroy', p.id)); };
const summary = (p) => {
    if (p.type === 'bxgy') return t('s_promos.bxgy', { buy: p.buy_qty, get: p.get_qty });
    const unit = p.type === 'percent' ? '%' : ' ' + t('s_promos.per_unit');
    return p.tiers?.length ? p.tiers.map((tier) => `${t('s_promos.from')} ${tier.min_qty}: ${tier.value}${unit}`).join(' · ') : `${p.value}${unit}`;
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_promos.promotions_and_discounts')" />
        <PageHeader :title="$t('s_promos.promotions_and_promotional_disco')" :subtitle="$t('s_promos.applied_automatically_to_invoice')" :crumbs="[{ label: 'المبيعات' }, { label: 'العروض والخصومات' }]">
            <template #actions>
                <Link v-if="$can('sales.promotions.create')" :href="route('sales.promotions.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('s_promos.new_promotion') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 text-xs">
            <select :value="filters.status" @change="setStatus" class="rounded-xl border-slate-200 text-xs"><option value="active">{{ $t('s_promos.active') }}</option><option value="inactive">{{ $t('s_promos.disabled') }}</option><option value="all">{{ $t('s_promos.all') }}</option></select>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('s_promos.promotion') }}</th><th class="px-4 py-2 text-start">{{ $t('s_promos.type_and_value') }}</th><th class="px-4 py-2 text-start">{{ $t('s_promos.covers') }}</th><th class="px-4 py-2 text-start">{{ $t('s_promos.documents') }}</th><th class="px-4 py-2 text-start">{{ $t('s_promos.period') }}</th><th class="px-4 py-2 text-start">{{ $t('s_promos.status') }}</th><th class="px-4 py-2 w-20"></th></tr></thead>
                <tbody>
                    <tr v-for="p in promotions" :key="p.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-bold">{{ p.name }}</td>
                        <td class="px-4 py-2"><span class="text-slate-500">{{ types[p.type] }}</span><br /><b class="font-mono">{{ summary(p) }}</b></td>
                        <td class="px-4 py-2">{{ p.product || p.category || $t('s_promo_form.all_items') }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ (p.documents || []).map((d) => documents[d]).join('، ') }}</td>
                        <td class="px-4 py-2 font-mono text-slate-500">{{ p.valid_from ? date(p.valid_from) : '—' }} → {{ p.valid_to ? date(p.valid_to) : '—' }}</td>
                        <td class="px-4 py-2"><span :class="p.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'" class="px-2 py-0.5 rounded-full border text-[11px] font-bold">{{ p.is_active ? $t('s_common.active_m') : $t('s_common.disabled_m') }}</span></td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            <Link v-if="$can('sales.promotions.edit')" :href="route('sales.promotions.edit', p.id)" class="text-slate-400 hover:text-indigo-600 me-2"><PencilSquareIcon class="w-4 h-4 inline" /></Link>
                            <button v-if="$can('sales.promotions.delete')" @click="remove(p)" class="text-rose-500 font-bold">{{ $t('s_promos.delete') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!promotions.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('s_promos.no_promotions') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

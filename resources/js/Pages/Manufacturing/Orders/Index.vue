<script setup>
import { useI18n } from 'vue-i18n';
import RowActions from '@/Components/UI/RowActions.vue';
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ orders: Object, filters: Object, counts: Object });
const search = ref(props.filters?.search || '');
const go = (extra = {}) => router.get(route('manufacturing.orders.index'), { search: search.value || undefined, status: props.filters?.status, ...extra }, { preserveState: true, replace: true });
const tabs = computed(() => ([['', t('mfg_manufacturing_orders_index.all')], ['draft', t('mfg_manufacturing_orders_index.draft')], ['released', t('mfg_manufacturing_orders_index.released')], ['in_progress', t('mfg_manufacturing_orders_index.in_progress')], ['completed', t('mfg_manufacturing_orders_index.completed')], ['closed', t('mfg_manufacturing_orders_index.closed')], ['cancelled', t('mfg_manufacturing_orders_index.cancelled')]]));
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_orders_index.production_orders')" />
        <PageHeader :title="$t('mfg_manufacturing_orders_index.production_orders')" :subtitle="$t('mfg_manufacturing_orders_index.make_to_order_manufacturing_at_a')" :crumbs="[{ label: $t('mfg_manufacturing_orders_index.manufacturing') }, { label: $t('mfg_manufacturing_orders_index.production_orders') }]">
            <template #actions>
                <Link v-if="$can('manufacturing.orders.create')" :href="route('manufacturing.orders.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_orders_index.new_production_order') }}</Link>
            </template>
        </PageHeader>
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <button v-for="[k, label] in tabs" :key="k" type="button" @click="go({ status: k || undefined })"
                :class="(filters?.status || '') === k ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200'" class="px-3 py-1.5 rounded-full text-xs font-bold">
                {{ label }}<span v-if="k && counts[k]" class="ms-1 opacity-70">{{ counts[k] }}</span>
            </button>
            <input v-model="search" @keyup.enter="go()" :placeholder="$t('mfg_manufacturing_orders_index.search_by_order_number_or_produc')" class="ms-auto text-xs rounded-xl border-slate-200 w-64" />
        </div>
        <div class="bg-white border border-slate-200/80 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.number') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.product') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.quantity') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.receiver') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.start') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.planned_end') }}</th><th class="text-start px-4 py-3">{{ $t('mfg_manufacturing_orders_index.status') }}</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50/60 cursor-pointer" @click="router.visit(route('manufacturing.orders.show', o.id))">
                        <td class="px-4 py-3 font-mono font-bold text-indigo-700">{{ o.order_number }}</td><td class="px-4 py-3 font-bold text-slate-800">{{ o.product?.name_ar }}</td>
                        <td class="px-4 py-3 font-mono">{{ num(o.quantity) }}</td><td class="px-4 py-3 font-mono">{{ num(o.received_quantity) }}</td>
                        <td class="px-4 py-3 font-mono">{{ String(o.planned_start || '').slice(0, 10) }}</td><td class="px-4 py-3 font-mono">{{ String(o.planned_end || '').slice(0, 10) }}</td>
                        <td class="px-4 py-3"><StatusPill :status="o.status" /></td>
                        <td class="px-4 py-3 text-end" @click.stop><RowActions :remove="o.status === 'draft' && $can('manufacturing.orders.delete') ? route('manufacturing.orders.destroy', o.id) : null" :confirm="$t('mfg_manufacturing_orders_index.delete_production_order_n', { n0: o.order_number })" /></td>
                    </tr>
                    <tr v-if="!orders.data.length"><td colspan="8" class="px-4 py-10 text-center text-slate-400">{{ $t('mfg_manufacturing_orders_index.no_orders') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="orders.links" class="mt-4" />
    </AppLayout>
</template>

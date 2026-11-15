<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusPill from '@/Components/Mfg/StatusPill.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({
    counts: Object, late: Array, wipTotal: Number, wipOrders: Array, pendingQc: Number, openSubcontracts: Number,
    capacity: Array, recent: Array, monthOutput: Number,
});

const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const barColor = (pct) => (pct > 100 ? 'bg-rose-500' : pct > 80 ? 'bg-amber-500' : 'bg-emerald-500');
</script>

<template>
    <AppLayout>
        <Head :title="$t('mfg_manufacturing_dashboard.manufacturing_dashboard')" />
        <PageHeader :title="$t('mfg_manufacturing_dashboard.manufacturing_dashboard')" :subtitle="$t('mfg_manufacturing_dashboard.production_orders_capacity_and_q')" :crumbs="[{ label: $t('mfg_manufacturing_dashboard.manufacturing') }, { label: $t('mfg_manufacturing_dashboard.manufacturing_dashboard') }]">
            <template #actions>
                <Link v-if="$can('manufacturing.orders.create')" :href="route('manufacturing.orders.create')" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-2">
                    <PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_dashboard.new_production_order') }}
                </Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <Link :href="route('manufacturing.orders.index', { status: 'in_progress' })" class="bg-white border border-slate-200/80 rounded-2xl p-4 hover:border-indigo-300">
                <p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_dashboard.in_progress') }}</p><p class="text-2xl font-black text-amber-600">{{ (counts.in_progress || 0) + (counts.released || 0) }}</p>
            </Link>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-4"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_dashboard.completed_awaiting_closing') }}</p><p class="text-2xl font-black text-emerald-600">{{ counts.completed || 0 }}</p></div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-4"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_dashboard.this_month_s_output_units') }}</p><p class="text-2xl font-black text-slate-800">{{ money(monthOutput) }}</p></div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-4"><p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_dashboard.work_in_progress') }}</p><p class="text-2xl font-black text-indigo-700">{{ money(wipTotal) }} <span class="text-xs">{{ $cur() }}</span></p></div>
            <Link :href="route('manufacturing.inspections.index', { result: 'pending' })" class="bg-white border border-slate-200/80 rounded-2xl p-4 hover:border-indigo-300">
                <p class="text-3xs font-bold text-slate-400">{{ $t('mfg_manufacturing_dashboard.inspections_awaiting_quality') }}</p><p class="text-2xl font-black" :class="pendingQc ? 'text-rose-600' : 'text-slate-800'">{{ pendingQc }}</p>
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white border border-slate-200/80 rounded-2xl p-5">
                <h2 class="font-black text-slate-800 text-sm mb-4">{{ $t('mfg_manufacturing_dashboard.work_center_load_next_four_weeks') }}</h2>
                <p v-if="!capacity.length" class="text-xs text-slate-400">{{ $t('mfg_manufacturing_dashboard.no_work_centers_yet') }}</p>
                <div v-for="c in capacity" :key="c.name" class="mb-3">
                    <div class="flex justify-between text-xs mb-1"><span class="font-bold text-slate-700">{{ c.name }}</span></div>
                    <div class="grid grid-cols-4 gap-2">
                        <div v-for="w in c.weeks" :key="w.start" class="text-center">
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden"><div :class="barColor(w.pct)" class="h-full" :style="{ width: Math.min(100, w.pct) + '%' }"></div></div>
                            <p class="text-3xs text-slate-500 mt-1 font-mono">{{ w.pct }}%</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-5">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_dashboard.overdue_orders') }}</h2>
                <p v-if="!late.length" class="text-xs text-emerald-600">{{ $t('mfg_manufacturing_dashboard.no_overdue_orders') }}</p>
                <Link v-for="o in late" :key="o.id" :href="route('manufacturing.orders.show', o.id)" class="flex justify-between items-center py-2 border-b border-slate-100 text-xs hover:bg-slate-50 px-1 rounded">
                    <span><b class="font-mono">{{ o.order_number }}</b> {{ o.product?.name_ar }}</span><span class="text-rose-600 font-mono">{{ String(o.planned_end).slice(0, 10) }}</span>
                </Link>
                <p class="text-3xs text-slate-400 mt-3">{{ $t('mfg_manufacturing_dashboard.at_subcontractors_n_orders', { n0: openSubcontracts }) }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white border border-slate-200/80 rounded-2xl p-5">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_dashboard.latest_orders') }}</h2>
                <Link v-for="o in recent" :key="o.id" :href="route('manufacturing.orders.show', o.id)" class="flex justify-between items-center py-2 border-b border-slate-100 text-xs hover:bg-slate-50 px-1 rounded">
                    <span><b class="font-mono">{{ o.order_number }}</b> {{ o.product?.name_ar }}</span>
                    <span class="flex items-center gap-2"><span class="font-mono">{{ money(o.received_quantity) }}/{{ money(o.quantity) }}</span><StatusPill :status="o.status" /></span>
                </Link>
            </div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-5">
                <h2 class="font-black text-slate-800 text-sm mb-3">{{ $t('mfg_manufacturing_dashboard.highest_wip_values') }}</h2>
                <p v-if="!wipOrders.length" class="text-xs text-slate-400">{{ $t('mfg_manufacturing_dashboard.no_outstanding_cost') }}</p>
                <Link v-for="o in wipOrders" :key="o.id" :href="route('manufacturing.orders.show', o.id)" class="flex justify-between items-center py-2 border-b border-slate-100 text-xs hover:bg-slate-50 px-1 rounded">
                    <span><b class="font-mono">{{ o.number }}</b> {{ o.product }}</span><span class="font-mono">{{ money(o.wip) }}</span>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>

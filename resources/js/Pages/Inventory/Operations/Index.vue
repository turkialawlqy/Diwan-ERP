<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ArrowsRightLeftIcon,
    AdjustmentsHorizontalIcon,
    BuildingStorefrontIcon,
    CubeIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { timeZone } from '@/utils/format';

const props = defineProps({
    stocks: Object,
    stats: Object,
});

</script>

<template>
    <AppLayout>
        <Head title="عمليات وحركات المخزون" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader title="حركات المخزون" :crumbs="[{ label: 'المخزون' }, { label: 'حركات المخزون' }]">
<template #actions>
<Link 
                        :href="route('inventory.operations.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
<Link v-if="$can('inventory.operations.create')" :href="route('inventory.operations.adjust.create')" class="px-4 py-2.5 bg-white border border-slate-200/80 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-bold shadow-xs transition-all flex items-center gap-1.5">
                        <AdjustmentsHorizontalIcon class="w-4 h-4 text-slate-600" />
                        <span>تسوية / جرد سريع</span>
                    </Link>
                    <Link v-if="$can('inventory.operations.create')" :href="route('inventory.operations.transfer.create')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5">
                        <ArrowsRightLeftIcon class="w-4 h-4 stroke-2" />
                        <span>تحويل بين المستودعات</span>
                    </Link>
</template>
</PageHeader>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">إجمالي الوحدات بالمخازن</p>
                        <h3 class="text-xl font-black text-slate-900">{{ stats?.total_units ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <CubeIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">المستودعات الفعالة</p>
                        <h3 class="text-xl font-black text-emerald-600">{{ stats?.warehouses_count ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">الأصناف الموزعة</p>
                        <h3 class="text-xl font-black text-slate-800">{{ stats?.stocked_products_count ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <CubeIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Stock Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="px-6 py-4">المنتج</th>
                                <th class="px-6 py-4">المستودع</th>
                                <th class="px-6 py-4 text-center">الكمية المتوفرة</th>
                                <th class="px-6 py-4 text-center">آخر تحديث</th>
                                <th class="px-6 py-4 text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="stock in stocks?.data || []" 
                                :key="stock.id"
                                class="hover:bg-slate-50/60 transition"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">
                                            <CubeIcon class="w-4 h-4" />
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-900">{{ stock.product?.name_ar }}</p>
                                            <p class="text-[10px] text-slate-400 font-mono">{{ stock.product?.sku }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 font-semibold text-slate-700">
                                        <BuildingStorefrontIcon class="w-3.5 h-3.5 text-slate-400" />
                                        <span>{{ stock.warehouse?.name_ar }}</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span 
                                        :class="stock.quantity > 5 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'"
                                        class="font-mono font-bold px-3 py-1 rounded-full text-xs border"
                                    >
                                        {{ stock.quantity }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center text-slate-400 font-mono text-[11px]" dir="ltr">
                                    {{ new Date(stock.updated_at).toLocaleDateString('ar-SA-u-nu-latn', { timeZone: timeZone() }) }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <Link v-if="$can('inventory.operations.create')" :href="route('inventory.operations.adjust.create', { product_id: stock.product_id, warehouse_id: stock.warehouse_id })" class="px-2.5 py-1 text-slate-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg text-xs font-bold transition inline-flex items-center gap-1">
                                        <AdjustmentsHorizontalIcon class="w-3.5 h-3.5" />
                                        <span>تسوية</span>
                                    </Link>
                                </td>
                            </tr>

                            <tr v-if="!stocks?.data || stocks.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <CubeIcon class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <span>لا توجد أرصدة مسجلة في المستودعات حالياً</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="stocks?.links && stocks.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ stocks.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ stocks.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ stocks.total }}</span> صنف
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in stocks.links" :key="i">
                            <Link 
                                v-if="link.url"
                                :href="link.url"
                                :class="[
                                    link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50',
                                    'min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs transition-colors'
                                ]"
                                v-html="link.label"
                            />
                            <span 
                                v-else
                                class="min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

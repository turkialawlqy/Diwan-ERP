<script setup>
import { ref, reactive, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ClipboardDocumentCheckIcon,
    BuildingStorefrontIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    warehouses: Array,
    selectedWarehouseId: [Number, String],
    stocks: Array,
});

const currentWarehouseId = ref(props.selectedWarehouseId || (props.warehouses?.[0]?.id || ''));

// Local copy of items for interactive physical counting
const items = ref(
    (props.stocks || []).map(s => ({
        ...s,
        physical_qty: s.system_qty,
        difference: 0,
    }))
);

watch(() => props.stocks, (newStocks) => {
    items.value = (newStocks || []).map(s => ({
        ...s,
        physical_qty: s.system_qty,
        difference: 0,
    }));
});

const changeWarehouse = () => {
    router.get(route('inventory.counts.index'), { warehouse_id: currentWarehouseId.value }, { preserveState: true });
};

const updateDifference = (item) => {
    item.difference = Number((item.physical_qty - item.system_qty).toFixed(2));
};

const reconcileForm = useForm({
    warehouse_id: currentWarehouseId.value,
    counts: [],
});

const submitReconciliation = () => {
    if (!confirm('هل أنت متأكد من اعتماد نتائج الجرد؟ سيتم تعديل كميات المخزون في النظام بناءً على العد الفعلي.')) {
        return;
    }

    reconcileForm.warehouse_id = currentWarehouseId.value;
    reconcileForm.counts = items.value.map(i => ({
        product_id: i.product_id,
        physical_qty: i.physical_qty,
    }));

    reconcileForm.post(route('inventory.counts.reconcile'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout>
        <Head title="جرد وتسوية المخزون - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('inventory.counts.index', { warehouse_id: currentWarehouseId })"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المخزون</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">جرد المخزون</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button 
                        @click="submitReconciliation"
                        :disabled="reconcileForm.processing || items.length === 0"
                        class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-emerald-500/20 transition-all flex items-center gap-1.5 disabled:opacity-50"
                    >
                        <CheckCircleIcon class="w-4 h-4 stroke-2" />
                        <span>اعتماد نتائج الجرد وتحديث النظام</span>
                    </button>
                </div>
            </div>

            <!-- Warehouse Selector Banner -->
            <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">المستودع المستهدف بالجرد</h3>
                        <p class="text-xs text-slate-400">اختر المستودع للبدء في مطابقة الرصيد الدفتري مع الفعلي</p>
                    </div>
                </div>

                <div class="w-full sm:w-72">
                    <select 
                        v-model="currentWarehouseId"
                        @change="changeWarehouse"
                        class="w-full text-xs font-bold rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                    >
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">
                            {{ w.name_ar }} ({{ w.code }})
                        </option>
                    </select>
                </div>
            </div>

            <!-- Stock Count Sheet Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-slate-900 text-sm">استمارة الجرد الفعلي للأصناف</h3>
                        <p class="text-xs text-slate-400 mt-0.5">أدخل الكميات المعدودة فعلياً في عمود "الرصيد الفعلي"</p>
                    </div>
                    <span class="text-xs font-bold text-slate-500 bg-slate-100 px-3 py-1 rounded-lg">
                        {{ items.length }} صنف مدرج
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="px-6 py-4">المنتج والرمز</th>
                                <th class="px-6 py-4 text-center">الرصيد الدفتري (النظام)</th>
                                <th class="px-6 py-4 text-center w-48">الرصيد الفعلي (العد)</th>
                                <th class="px-6 py-4 text-center">الفارق / الانحراف</th>
                                <th class="px-6 py-4 text-center">الحالة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="item in items" 
                                :key="item.id"
                                class="hover:bg-slate-50/60 transition"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-bold text-slate-900">{{ item.product_name }}</p>
                                    <p class="text-[10px] text-slate-400 font-mono">{{ item.sku }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-center font-mono font-bold text-slate-700">
                                    {{ item.system_qty }}
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <input 
                                        v-model.number="item.physical_qty"
                                        @input="updateDifference(item)"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="w-32 text-center text-xs font-mono font-bold rounded-xl border-slate-300 focus:border-indigo-600 focus:ring-indigo-600 mx-auto"
                                    />
                                </td>
                                <td class="px-5 py-3.5 text-center font-mono font-bold">
                                    <span 
                                        :class="item.difference === 0 ? 'text-slate-400' : (item.difference > 0 ? 'text-emerald-600' : 'text-rose-600')"
                                    >
                                        {{ item.difference > 0 ? '+' + item.difference : item.difference }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <span 
                                        v-if="item.difference === 0"
                                        class="inline-flex items-center gap-1 bg-slate-100 text-slate-600 text-[11px] font-bold px-2 py-0.5 rounded-md"
                                    >
                                        <CheckCircleIcon class="w-3.5 h-3.5 text-emerald-600" />
                                        <span>مطابق</span>
                                    </span>
                                    <span 
                                        v-else-if="item.difference > 0"
                                        class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 text-[11px] font-bold px-2 py-0.5 rounded-md border border-emerald-200"
                                    >
                                        <span>فائض جردي</span>
                                    </span>
                                    <span 
                                        v-else
                                        class="inline-flex items-center gap-1 bg-rose-50 text-rose-700 text-[11px] font-bold px-2 py-0.5 rounded-md border border-rose-200"
                                    >
                                        <ExclamationTriangleIcon class="w-3.5 h-3.5" />
                                        <span>عجز جردي</span>
                                    </span>
                                </td>
                            </tr>

                            <tr v-if="items.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <ClipboardDocumentCheckIcon class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <span>لا توجد أصناف مسجلة في هذا المستودع لبدء الجرد</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

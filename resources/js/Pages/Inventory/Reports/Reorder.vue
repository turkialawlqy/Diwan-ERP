<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { qty, money } from '@/utils/format';

defineProps({ rows: Object });
</script>

<template>
    <AppLayout>
        <Head title="حد إعادة الطلب" />
        <PageHeader title="أصناف وصلت حد إعادة الطلب" subtitle="مع الكمية المقترحة للشراء" :crumbs="[{ label: 'المستودعات' }, { label: 'إعادة الطلب' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="text-end px-3 py-2">الرصيد</th><th class="text-end px-3 py-2">حد الطلب</th><th class="text-end px-3 py-2">الكمية المقترحة</th><th class="text-end px-3 py-2">التكلفة التقديرية</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-semibold">{{ r.name_ar }} <span class="text-slate-400 font-mono">{{ r.sku }}</span></td>
                        <td class="px-3 py-2 text-end font-mono text-rose-600 font-bold">{{ qty(r.on_hand) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ qty(r.reorder_level) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ qty(r.suggested) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(r.suggested * r.purchase_price) }}</td>
                    </tr>
                    <tr v-if="!rows.data.length"><td colspan="5" class="py-10 text-center text-slate-400">لا توجد أصناف تحت حد الطلب</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money } from '@/utils/format';

defineProps({ portal: Object, payments: Object });
const method = { cash: 'نقدًا', card: 'بطاقة', bank_transfer: 'تحويل بنكي', cheque: 'شيك' };
</script>

<template>
    <Head title="الدفعات" />
    <PortalLayout :portal="portal" active="payments">
        <h1 class="text-lg font-black text-slate-900 mb-3">الدفعات المسددة</h1>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">السند</th><th class="px-4 py-2 text-start">التاريخ</th><th class="px-4 py-2 text-start">الطريقة</th><th class="px-4 py-2 text-start">المبلغ</th></tr></thead>
                <tbody>
                    <tr v-for="p in payments.data" :key="p.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono font-bold">{{ p.payment_number }}</td><td class="px-4 py-2 font-mono">{{ p.payment_date?.slice(0, 10) }}</td><td class="px-4 py-2">{{ method[p.payment_method] || p.payment_method }}</td><td class="px-4 py-2 font-mono">{{ money(p.amount) }}</td></tr>
                    <tr v-if="!payments.data.length"><td colspan="4" class="py-8 text-center text-slate-400">لا دفعات.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="payments" />
    </PortalLayout>
</template>

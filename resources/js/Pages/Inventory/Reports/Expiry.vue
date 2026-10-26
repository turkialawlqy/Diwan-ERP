<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { qty, date } from '@/utils/format';

const props = defineProps({ rows: Object, days: Number, today: String });
const days = ref(props.days);
const apply = () => router.get(route('inventory.reports.expiry'), { days: days.value }, { preserveState: true });
</script>

<template>
    <AppLayout>
        <Head title="تواريخ الصلاحية" />
        <PageHeader title="تقرير تواريخ الصلاحية" :subtitle="`التشغيلات المنتهية أو التي تنتهي خلال ${days} يوماً`" :crumbs="[{ label: 'المستودعات' }, { label: 'الصلاحية' }]">
            <template #actions><form @submit.prevent="apply" class="flex gap-2 text-xs"><input v-model="days" type="number" min="0" class="w-24 rounded-xl border-slate-200 text-xs" /><button class="px-4 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('ui.apply') }}</button></form></template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="text-start px-3 py-2">التشغيلة</th><th class="text-start px-3 py-2">المستودع</th><th class="px-3 py-2">تاريخ الانتهاء</th><th class="text-end px-3 py-2">الكمية</th></tr></thead>
                <tbody>
                    <tr v-for="b in rows.data" :key="b.id" class="border-t border-slate-100" :class="date(b.expiry_date) < today ? 'bg-rose-50/60' : ''">
                        <td class="px-3 py-2 font-semibold">{{ b.product?.name_ar }}</td><td class="px-3 py-2 font-mono">{{ b.batch_number }}</td><td class="px-3 py-2">{{ b.warehouse?.name_ar }}</td>
                        <td class="px-3 py-2 text-center font-mono" :class="date(b.expiry_date) < today ? 'text-rose-600 font-bold' : 'text-amber-600'">{{ date(b.expiry_date) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ qty(b.quantity) }}</td>
                    </tr>
                    <tr v-if="!rows.data.length"><td colspan="5" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>

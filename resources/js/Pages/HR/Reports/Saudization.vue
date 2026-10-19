<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';

const props = defineProps({ groups: Array, total: Object, thresholds: Object, filters: Object });
const filters = reactive({ ...props.filters });
const reload = () => router.get(route('hr.reports.saudization'), filters, { preserveState: true, preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="مؤشر السعودة" />
        <PageHeader title="مؤشر السعودة (نطاقات)" subtitle="مؤشر تقديري من بيانات الموظفين؛ النطاق الرسمي يصدر من منصة قوى" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'مؤشر السعودة' }]">
            <template #actions>
                <input v-model="filters.date" type="date" @change="reload" class="rounded-xl border-slate-200 text-xs" />
                <a v-if="$can('hr.reports.export')" :href="route('hr.reports.saudization', { date: filters.date, export: 'csv' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تصدير CSV</a>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">إجمالي الموظفين</p><p class="font-black text-base">{{ total.headcount }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">السعوديون (المحتسبون)</p><p class="font-black text-base">{{ total.saudis }} <span class="text-slate-400 text-xs">({{ total.counted }})</span></p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">غير السعوديين</p><p class="font-black text-base">{{ total.non_saudis }}</p></div>
            <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-3"><p class="text-indigo-700">نسبة السعودة</p><p class="font-black text-base text-indigo-800">{{ total.ratio }}%</p></div>
        </div>
        <p class="mb-3 text-xs text-slate-500">يُحتسب السعودي كاملاً إذا بلغ أجره (الأساسي + السكن) {{ money(thresholds.full) }}، ونصفاً من {{ money(thresholds.half) }}، ولا يُحتسب دون ذلك<span v-if="total.below_half"> ({{ total.below_half }} موظف سعودي غير محتسب لانخفاض الأجر)</span>.</p>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs min-w-[640px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="p-2 text-start">الفرع</th><th class="p-2 text-start">القسم</th><th class="p-2">الموظفون</th><th class="p-2">السعوديون</th><th class="p-2">المحتسبون</th><th class="p-2">غير السعوديين</th><th class="p-2 text-end">النسبة</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="g in groups" :key="g.branch + g.department">
                        <td class="p-2">{{ g.branch }}</td><td class="p-2">{{ g.department }}</td><td class="p-2 text-center">{{ g.headcount }}</td><td class="p-2 text-center">{{ g.saudis }}</td>
                        <td class="p-2 text-center">{{ g.counted }}</td><td class="p-2 text-center">{{ g.non_saudis }}</td><td class="p-2 text-end font-bold">{{ g.ratio }}%</td>
                    </tr>
                    <tr v-if="!groups.length"><td colspan="7" class="p-6 text-center text-slate-400">لا يوجد موظفون على رأس العمل في هذا التاريخ.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

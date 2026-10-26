<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';

const props = defineProps({ rows: Array, totals: Object, ledger: Number, difference: Number, missing_numbers: Number, filters: Object, establishment: String });
const filters = reactive({ ...props.filters });
const reload = () => router.get(route('hr.reports.gosi'), filters, { preserveState: true, preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="تقرير التأمينات الشهري" />
        <PageHeader title="تقرير اشتراكات التأمينات الاجتماعية" :subtitle="establishment ? `رقم المنشأة في التأمينات: ${establishment}` : 'من مسيرات الشهر المعتمدة والمصروفة'" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'تقرير التأمينات' }]">
            <template #actions>
                <input v-model="filters.month" type="month" @change="reload" class="rounded-xl border-slate-200 text-xs" />
                <a v-if="$can('hr.reports.export')" :href="route('hr.reports.gosi', { month: filters.month, export: 'csv' })" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تصدير CSV</a>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">الأجور الخاضعة</p><p class="font-black font-mono text-base">{{ money(totals.base) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">حصة الموظفين</p><p class="font-black font-mono text-base">{{ money(totals.employee) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><p class="text-slate-500">حصة المنشأة</p><p class="font-black font-mono text-base">{{ money(totals.employer) }}</p></div>
            <div class="bg-white border rounded-2xl p-3" :class="difference === 0 ? 'border-emerald-200' : 'border-rose-300'">
                <p class="text-slate-500">المستحق للتأمينات</p><p class="font-black font-mono text-base">{{ money(totals.total) }}</p>
                <p class="mt-1" :class="difference === 0 ? 'text-emerald-700' : 'text-rose-600'">{{ difference === 0 ? 'يطابق قيد الاستحقاق في حساب التأمينات المستحقة' : `يختلف عن القيد (${money(ledger)}) بمقدار ${money(difference)}` }}</p>
            </div>
        </div>
        <p v-if="missing_numbers" class="mb-3 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800">{{ missing_numbers }} موظف بلا رقم اشتراك في التأمينات؛ أكمل بياناتهم في ملف الموظف.</p>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs min-w-[760px]">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="p-2 text-start">رقم الاشتراك</th><th class="p-2 text-start">الهوية</th><th class="p-2 text-start">الموظف</th><th class="p-2">الجنسية</th><th class="p-2 text-end">الأجر الخاضع</th><th class="p-2 text-end">حصة الموظف</th><th class="p-2 text-end">حصة المنشأة</th><th class="p-2 text-end">الإجمالي</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in rows" :key="r.employee_id">
                        <td class="p-2 font-mono">{{ r.gosi_number || '—' }}</td><td class="p-2 font-mono">{{ r.national_id }}</td><td class="p-2">{{ r.name }}</td><td class="p-2 text-center">{{ r.nationality }}</td>
                        <td class="p-2 text-end font-mono">{{ money(r.base) }}</td><td class="p-2 text-end font-mono">{{ money(r.employee) }}</td><td class="p-2 text-end font-mono">{{ money(r.employer) }}</td><td class="p-2 text-end font-mono font-bold">{{ money(r.total) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="8" class="p-6 text-center text-slate-400">لا توجد مسيرات معتمدة لهذا الشهر.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

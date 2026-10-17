<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';
import { reactive } from 'vue';

const props = defineProps({ report: Object, filters: Object });
const form = reactive({ ...props.filters });
const apply = () => router.get(route('accounting.reports.aging'), form, { preserveState: true });
const columns = [['current', 'غير مستحق'], ['d30', '1-30 يوم'], ['d60', '31-60 يوم'], ['d90', '61-90 يوم'], ['d90plus', 'أكثر من 90 يوم'], ['total', 'الإجمالي']];
</script>

<template>
    <AppLayout>
        <Head title="أعمار الديون" />
        <PageHeader :title="filters.kind === 'payables' ? 'أعمار الذمم الدائنة (الموردون)' : 'أعمار الذمم المدينة (العملاء)'" :subtitle="`كما في ${filters.as_of}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'أعمار الديون' }]" />

        <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-wrap items-end gap-3 text-xs mb-4">
            <label><span class="block font-bold text-slate-600 mb-1">النوع</span>
                <select v-model="form.kind" class="rounded-xl border-slate-200 text-xs"><option value="receivables">العملاء</option><option value="payables">الموردون</option></select>
            </label>
            <label><span class="block font-bold text-slate-600 mb-1">كما في</span><input v-model="form.as_of" type="date" class="rounded-xl border-slate-200 text-xs" /></label>
            <button class="rounded-xl bg-indigo-600 text-white font-bold px-5 py-2">{{ $t('ui.apply') }}</button>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="text-start px-3 py-2">{{ $t('ui.name') }}</th><th v-for="c in columns" :key="c[0]" class="text-end px-3 py-2">{{ c[1] }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in report.rows" :key="row.id ?? `none`" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-semibold text-slate-700">{{ row.name }}</td>
                        <td v-for="c in columns" :key="c[0]" :class="['px-3 py-2 text-end font-mono', c[0] === 'd90plus' && row[c[0]] ? 'text-rose-600 font-bold' : '']">{{ row[c[0]] ? money(row[c[0]]) : '' }}</td>
                    </tr>
                    <tr v-if="!report.rows.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
                <tfoot class="bg-slate-100 font-black">
                    <tr><td class="px-3 py-2">{{ $t('ui.total') }}</td><td v-for="c in columns" :key="c[0]" class="px-3 py-2 text-end font-mono">{{ money(report.totals[c[0]]) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';

defineProps({ years: Array });

const form = useForm({ start_date: new Date().getFullYear() + 1 + '-01-01' });
const create = () => form.post(route('accounting.fiscal-years.store'), { preserveScroll: true });
const setStatus = (period, status) => router.put(route('accounting.fiscal-periods.update', period.id), { status }, { preserveScroll: true });
const closeYear = (year) => confirm(`إقفال السنة المالية ${year.name}؟ سيتم ترحيل صافي الربح/الخسارة إلى الأرباح المحتجزة وإغلاق جميع الفترات.`) && router.post(route('accounting.fiscal-years.close', year.id), {}, { preserveScroll: true });
const reopenYear = (year) => confirm(`إعادة فتح السنة ${year.name}؟ سيتم عكس قيد الإقفال.`) && router.post(route('accounting.fiscal-years.reopen', year.id), {}, { preserveScroll: true });

const statusClass = { open: 'bg-emerald-50 text-emerald-700 border-emerald-200', closed: 'bg-amber-50 text-amber-700 border-amber-200', locked: 'bg-slate-100 text-slate-600 border-slate-300' };
const statusLabel = { open: 'مفتوحة', closed: 'مغلقة', locked: 'مقفلة نهائياً' };
const expanded = ref(null);
</script>

<template>
    <AppLayout>
        <Head title="السنوات المالية" />
        <PageHeader title="السنوات والفترات المالية" subtitle="إغلاق الفترات يمنع الترحيل بتواريخها، والإقفال السنوي يرحل نتيجة السنة للأرباح المحتجزة" :crumbs="[{ label: 'المحاسبة' }, { label: 'السنوات المالية' }]">
            <template #actions>
                <form v-if="$can('accounting.fiscal_years.create')" @submit.prevent="create" class="flex items-center gap-2 text-xs">
                    <input v-model="form.start_date" type="date" class="rounded-xl border-slate-200 text-xs" />
                    <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">إنشاء سنة مالية</button>
                </form>
            </template>
        </PageHeader>
        <p v-if="form.errors.start_date" class="text-xs text-rose-600 mb-3">{{ form.errors.start_date }}</p>

        <div class="space-y-3">
            <div v-for="year in years" :key="year.id" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <button @click="expanded = expanded === year.id ? null : year.id" class="text-start">
                        <span class="font-black text-slate-800">السنة المالية {{ year.name }}</span>
                        <span class="text-xs text-slate-500 ms-2">{{ date(year.start_date) }} → {{ date(year.end_date) }}</span>
                    </button>
                    <div class="flex items-center gap-2">
                        <span :class="[statusClass[year.status], 'px-2.5 py-0.5 rounded-full border text-[11px] font-bold']">{{ statusLabel[year.status] }}</span>
                        <template v-if="$can('accounting.fiscal_years.approve')">
                            <button v-if="year.status === 'open'" @click="closeYear(year)" class="px-3 py-1.5 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">إقفال السنة</button>
                            <button v-else @click="reopenYear(year)" class="px-3 py-1.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-bold">إعادة فتح</button>
                        </template>
                    </div>
                </div>
                <div v-if="expanded === year.id" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 p-4 border-t border-slate-100 bg-slate-50/50">
                    <div v-for="p in year.periods" :key="p.id" class="bg-white border border-slate-200 rounded-xl p-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold">{{ p.name }}</span>
                            <span :class="[statusClass[p.status], 'px-1.5 rounded border text-[10px]']">{{ statusLabel[p.status] }}</span>
                        </div>
                        <select v-if="$can('accounting.fiscal_years.manage') && p.status !== 'locked'" :value="p.status" @change="setStatus(p, $event.target.value)" class="mt-2 w-full rounded-lg border-slate-200 text-[11px] py-1">
                            <option value="open">فتح</option>
                            <option value="closed">إغلاق</option>
                            <option value="locked">قفل نهائي</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

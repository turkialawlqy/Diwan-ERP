<script setup>
import { useI18n } from 'vue-i18n';
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';
import { ChevronDownIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ years: Array, periodTypes: Object });

const setStatus = (period, status) => router.put(route('accounting.fiscal-periods.update', period.id), { status }, { preserveScroll: true });
const reopenYear = (year) => confirm(t('a_years.confirm_reopen', { name: year.name })) && router.post(route('accounting.fiscal-years.reopen', year.id), {}, { preserveScroll: true });

const statusClass = { open: 'bg-emerald-50 text-emerald-700 border-emerald-200', closed: 'bg-amber-50 text-amber-700 border-amber-200', locked: 'bg-slate-100 text-slate-600 border-slate-300' };
const statusLabel = computed(() => ({ open: t('a_years.st_open'), closed: t('a_years.st_closed'), locked: t('a_years.st_locked') }));
const expanded = ref(null);
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_years.fiscal_years')" />
        <PageHeader :title="$t('a_years.fiscal_years_and_periods')" :subtitle="$t('a_years.closing_periods_blocks_posting_o')" :crumbs="[{ label: 'المحاسبة' }, { label: 'السنوات المالية' }]">
            <template #actions>
                <Link v-if="$can('accounting.fiscal_years.create')" :href="route('accounting.fiscal-years.create')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('a_years.create_a_fiscal_year') }}</Link>
            </template>
        </PageHeader>
        
        <div class="space-y-3">
            <div v-for="year in years" :key="year.id" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <button @click="expanded = expanded === year.id ? null : year.id" class="text-start">
                        <span class="font-black text-slate-800">{{ $t('a_period_end.fiscal_year') }} {{ year.name }}</span>
                        <span class="text-xs text-slate-500 ms-2">{{ date(year.start_date) }} → {{ date(year.end_date) }}</span>
                        <span class="text-[11px] text-slate-400 ms-2">· {{ $t('a_years.periods_of') }} {{ (periodTypes?.[year.period_type] || '').replace(/ \(.*\)/, '') }}</span>
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="expanded = expanded === year.id ? null : year.id" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-700 text-xs font-bold" data-toggle-periods>
                            <ChevronDownIcon class="w-4 h-4 transition" :class="expanded === year.id ? 'rotate-180' : ''" />
                            {{ expanded === year.id ? $t('a_years.hide_periods') : $t('a_years.show_periods', { n: year.periods.length }) }}
                        </button>
                        <span :class="[statusClass[year.status], 'px-2.5 py-0.5 rounded-full border text-[11px] font-bold']">{{ statusLabel[year.status] }}</span>
                        <template v-if="$can('accounting.fiscal_years.approve')">
                            <Link v-if="year.status === 'open'" :href="route('accounting.fiscal-years.close-form', year.id)" class="px-3 py-1.5 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold" data-close-year>{{ $t('a_years.close_the_year') }}</Link>
                            <button v-else @click="reopenYear(year)" class="px-3 py-1.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-bold">{{ $t('a_years.reopen') }}</button>
                        </template>
                    </div>
                </div>
                <div v-if="expanded === year.id" :class="year.periods.length <= 4 ? 'lg:grid-cols-4' : (year.periods.length <= 12 ? 'lg:grid-cols-6' : 'lg:grid-cols-8')" class="grid grid-cols-2 sm:grid-cols-3 gap-2 p-4 border-t border-slate-100 bg-slate-50/50">
                    <div v-for="p in year.periods" :key="p.id" class="bg-white border border-slate-200 rounded-xl p-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold">{{ p.name }}</span>
                            <span :class="[statusClass[p.status], 'px-1.5 rounded border text-[10px]']">{{ statusLabel[p.status] }}</span>
                        </div>
                        <select v-if="$can('accounting.fiscal_years.manage') && p.status !== 'locked'" :value="p.status" @change="setStatus(p, $event.target.value)" class="mt-2 w-full rounded-lg border-slate-200 text-[11px] py-1">
                            <option value="open">{{ $t('a_years.open') }}</option>
                            <option value="closed">{{ $t('a_years.close') }}</option>
                            <option value="locked">{{ $t('a_years.lock_permanently') }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

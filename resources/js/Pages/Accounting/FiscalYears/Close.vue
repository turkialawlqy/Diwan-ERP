<script setup>
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';
import { amount } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ year: Object, branches: Array, warnings: Array, ended: Boolean });
const mode = ref('all');
const pending = computed(() => props.branches.filter((b) => !b.closed));
const blocked = computed(() => pending.value.filter((b) => !b.ready));
const ready = computed(() => pending.value.filter((b) => b.ready));
const canClose = computed(() => props.ended && (mode.value === 'all' ? !blocked.value.length && pending.value.length >= 0 : ready.value.length > 0));
const submit = () => {
    if (confirm(t('a_year_close.confirm', { name: props.year.name }))) {
        router.post(route('accounting.fiscal-years.close', props.year.id), { mode: mode.value });
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_year_close.close_the_fiscal_year')" />
        <PageHeader :title="`${$t('a_year_close.close_the_fiscal_year')} ${year.name}`" :subtitle="`${date(year.start_date)} → ${date(year.end_date)}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'السنوات المالية', href: route('accounting.fiscal-years.index') }, { label: 'إقفال' }]">
            <template #actions>
                <Link v-if="$can('accounting.controls.view')" :href="route('accounting.controls.show', { report: 'closing-checklist', fiscal_year_id: year.id })" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:text-indigo-600" data-closing-checklist-link>{{ $t('controls.closing_checklist') }}</Link>
            </template>
        </PageHeader>

        <p v-if="!ended" class="mb-4 border border-amber-200 bg-amber-50 text-amber-700 rounded-xl px-4 py-3 text-xs font-bold">{{ $t('a_year_close.not_ended', { date: date(year.end_date) }) }}</p>
        <div v-for="w in warnings" :key="w" class="mb-3 border border-amber-200 bg-amber-50 text-amber-800 rounded-xl px-4 py-3 text-xs" data-close-warning>⚠ {{ w }}</div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-4">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('a_year_close.branch') }}</th><th class="px-4 py-2">{{ $t('a_year_close.draft_entries') }}</th><th class="text-end px-4 py-2">{{ $t('a_year_close.net_result') }}</th><th class="px-4 py-2">{{ $t('a_year_close.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="b in branches" :key="b.branch_id ?? 'none'" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-bold">{{ b.name }}</td>
                        <td class="px-4 py-2 text-center font-mono" :class="b.drafts ? 'text-rose-600 font-bold' : ''">{{ b.drafts }}</td>
                        <td class="px-4 py-2 text-end font-mono" :class="b.net >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ amount(b.net) }}</td>
                        <td class="px-4 py-2 text-center">
                            <span v-if="b.closed" class="text-slate-500 font-bold">{{ $t('a_year_close.closed') }}</span>
                            <span v-else-if="b.ready" class="text-emerald-600 font-bold">{{ $t('a_year_close.ready') }}</span>
                            <span v-else class="text-rose-600 font-bold">{{ $t('a_year_close.has_draft_entries') }}</span>
                        </td>
                    </tr>
                    <tr v-if="!branches.length"><td colspan="4" class="py-8 text-center text-slate-400">{{ $t('a_year_close.no_transactions_in_this_year') }}</td></tr>
                </tbody>
            </table>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs space-y-3">
            <label class="flex items-start gap-2"><input type="radio" v-model="mode" value="all" class="mt-0.5" /><span><b>{{ $t('a_year_close.all_or_nothing') }}</b> {{ $t('a_year_close.the_system_closes_only_if_every') }}</span></label>
            <label class="flex items-start gap-2"><input type="radio" v-model="mode" value="passed" class="mt-0.5" /><span><b>{{ $t('a_year_close.close_only_the_ready_branches') }}</b> {{ $t('a_year_close.branches_that_passed_are_closed') }}</span></label>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="button" :disabled="!canClose" @click="submit" class="px-5 py-2 rounded-xl bg-amber-600 text-white font-bold disabled:opacity-40" data-confirm-close>{{ $t('a_year_close.close_the_year') }}</button>
                <Link :href="route('accounting.fiscal-years.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('a_year_close.back') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>

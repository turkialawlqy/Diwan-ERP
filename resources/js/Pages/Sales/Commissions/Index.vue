<script setup>
import { useI18n } from 'vue-i18n';
import { reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { amount } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ rows: Array, filters: Object });
const f = reactive({ year: props.filters.year, month: props.filters.month });
const months = computed(() => ['cmp.month_1', 'cmp.month_2', 'cmp.month_3', 'cmp.month_4', 'cmp.month_5', 'cmp.month_6', 'cmp.month_7', 'cmp.month_8', 'cmp.month_9', 'cmp.month_10', 'cmp.month_11', 'cmp.month_12'].map((x) => t(x)));
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
const apply = () => router.get(route('sales.commissions.index'), { ...f }, { preserveState: true, replace: true });
const post = (r) => {
    if (confirm(t('s_comm.confirm_post', { name: r.name, amount: amount(r.total), month: months.value[f.month - 1], year: f.year }))) {
        router.post(route('sales.commissions.post'), { user_id: r.user_id, year: f.year, month: f.month }, { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_comm.sales_commissions')" />
        <PageHeader :title="$t('s_comm.sales_commissions')" :subtitle="$t('s_comm.each_representative_s_net_sales')" :crumbs="[{ label: 'المبيعات' }, { label: 'عمولات المبيعات' }]">
            <template #actions>
                <Link v-if="$can('sales.commissions.view')" :href="route('sales.commission-plans.index')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700">{{ $t('s_comm.commission_plans') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex gap-2 text-xs">
            <select v-model.number="f.month" @change="apply" class="rounded-xl border-slate-200 text-xs"><option v-for="(m, i) in months" :key="i" :value="i + 1">{{ m }}</option></select>
            <select v-model.number="f.year" @change="apply" class="rounded-xl border-slate-200 text-xs"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('s_comm.sales_rep') }}</th><th class="px-4 py-2 text-start">{{ $t('s_comm.plan') }}</th><th class="px-4 py-2 text-end">{{ $t('s_comm.sales') }}</th><th class="px-4 py-2 text-end">{{ $t('s_comm.returns') }}</th><th class="px-4 py-2 text-end">{{ $t('s_comm.net') }}</th><th class="px-4 py-2 text-end">{{ $t('s_comm.commission') }}</th><th class="px-4 py-2 text-end">{{ $t('s_comm.target_bonus') }}</th><th class="px-4 py-2 text-end">{{ $t('s_comm.total') }}</th><th class="px-4 py-2"></th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.user_id" class="border-t border-slate-100" data-commission-row>
                        <td class="px-4 py-2 font-bold">{{ r.name }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ r.plan || '—' }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ amount(r.sales) }}</td>
                        <td class="px-4 py-2 text-end font-mono text-rose-600">{{ amount(r.returns) }}</td>
                        <td class="px-4 py-2 text-end font-mono font-bold">{{ amount(r.net) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ amount(r.commission) }}</td>
                        <td class="px-4 py-2 text-end font-mono text-emerald-600">{{ amount(r.bonus) }}</td>
                        <td class="px-4 py-2 text-end font-mono font-black text-indigo-700">{{ amount(r.total) }}</td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            <span v-if="r.posted" class="text-emerald-600 font-bold">{{ $t('s_comm.posted_to_payroll') }}</span>
                            <button v-else-if="r.total > 0 && $can('sales.commissions.post')" @click="post(r)" class="text-indigo-600 font-bold" :title="r.employee_id ? '' : $t('s_comm.not_linked')">{{ $t('s_comm.post_to_payroll') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="9" class="py-10 text-center text-slate-400">{{ $t('s_comm.no_sales_by_representatives_this') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

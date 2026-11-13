<script setup>
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { amount } from '@/utils/format';
import { PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

defineProps({ plans: Array, types: Object });
const remove = (p) => { if (confirm(t('s_comm_plans.confirm_delete', { name: p.name }))) router.delete(route('sales.commission-plans.destroy', p.id)); };
const summary = (p) => (p.type === 'flat' ? t('s_comm_plans.flat', { rate: p.rate }) : (p.tiers || []).map((tier) => `${t('s_promos.from')} ${amount(tier.from)}: ${tier.rate}%`).join(' · '));
</script>

<template>
    <AppLayout>
        <Head :title="$t('s_comm_plans.commission_plans')" />
        <PageHeader :title="$t('s_comm_plans.commission_plans')" :crumbs="[{ label: 'المبيعات' }, { label: 'عمولات المبيعات', href: route('sales.commissions.index') }, { label: 'الخطط' }]">
            <template #actions>
                <Link v-if="$can('sales.commissions.create')" :href="route('sales.commission-plans.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('s_comm_plans.new_plan') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('s_comm_plans.plan') }}</th><th class="px-4 py-2 text-start">{{ $t('s_comm_plans.rule') }}</th><th class="px-4 py-2 text-start">{{ $t('s_comm_plans.target_and_bonus') }}</th><th class="px-4 py-2 text-start">{{ $t('s_comm_plans.representatives') }}</th><th class="px-4 py-2 text-start">{{ $t('s_comm_plans.status') }}</th><th class="px-4 py-2 w-24"></th></tr></thead>
                <tbody>
                    <tr v-for="p in plans" :key="p.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-bold">{{ p.name }}</td>
                        <td class="px-4 py-2"><span class="text-slate-500">{{ types[p.type] }}</span><br /><b class="font-mono">{{ summary(p) }}</b></td>
                        <td class="px-4 py-2 font-mono">{{ p.target_amount ? `${amount(p.target_amount)} ← ${amount(p.bonus_amount)}` : '—' }}</td>
                        <td class="px-4 py-2">{{ p.users_count }}</td>
                        <td class="px-4 py-2"><span :class="p.is_active ? 'text-emerald-600' : 'text-slate-400'" class="font-bold">{{ p.is_active ? $t('s_common.active_f') : $t('s_common.disabled_f') }}</span></td>
                        <td class="px-4 py-2 text-end whitespace-nowrap">
                            <Link v-if="$can('sales.commissions.edit')" :href="route('sales.commission-plans.edit', p.id)" class="text-slate-400 hover:text-indigo-600 me-2"><PencilSquareIcon class="w-4 h-4 inline" /></Link>
                            <button v-if="$can('sales.commissions.delete')" @click="remove(p)" class="text-rose-500 font-bold">{{ $t('s_comm_plans.delete') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!plans.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('s_comm_plans.no_plans') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

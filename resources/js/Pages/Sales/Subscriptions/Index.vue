<script setup>
import RowActions from '@/Components/UI/RowActions.vue';
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

const props = defineProps({ subscriptions: Object, filters: Object, stats: Object, renewals: Array, plans: Array, statuses: Object });
const f = reactive({ search: props.filters.search, status: props.filters.status, plan: props.filters.plan });
const apply = () => router.get(route('sales.subscriptions.index'), { search: f.search || undefined, status: f.status || undefined, plan: f.plan || undefined }, { preserveState: true, replace: true });
const tone = { trial: 'bg-sky-50 text-sky-700 border-sky-200', active: 'bg-emerald-50 text-emerald-700 border-emerald-200', paused: 'bg-slate-100 text-slate-600 border-slate-200', past_due: 'bg-rose-50 text-rose-700 border-rose-200', cancelled: 'bg-slate-100 text-slate-500 border-slate-200', expired: 'bg-amber-50 text-amber-700 border-amber-200' };
</script>

<template>
    <AppLayout>
        <Head title="اشتراكات العملاء" />
        <PageHeader title="اشتراكات العملاء" subtitle="خدمات دورية تُفوتَر تلقائيًا" :crumbs="[{ label: 'المبيعات' }, { label: 'اشتراكات العملاء' }]">
            <template #actions>
                <Link v-if="$can('sales.service_plans.view')" :href="route('sales.service-plans.index')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 text-xs font-bold">الخطط</Link>
                <Link v-if="$can('sales.subscriptions.create')" :href="route('sales.subscriptions.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-subscription><PlusIcon class="w-4 h-4" /> اشتراك جديد</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="الإيراد الشهري المتكرر (قبل الضريبة)" :value="money(stats.mrr)" tone="emerald" />
            <StatCard label="اشتراكات سارية" :value="stats.live" tone="indigo" />
            <StatCard label="متعثرة" :value="stats.past_due" :tone="stats.past_due ? 'rose' : 'slate'" />
            <StatCard label="أُلغيت آخر 30 يومًا" :value="stats.cancelled_30" tone="amber" />
        </div>

        <div v-if="renewals.length" class="bg-white border border-slate-200 rounded-2xl p-4 mb-4" data-renewals>
            <h2 class="text-xs font-bold text-slate-800 mb-2">تجديدات الـ 30 يومًا القادمة</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 text-xs">
                <Link v-for="r in renewals" :key="r.id" :href="route('sales.subscriptions.show', r.id)" class="flex items-center justify-between py-1 border-b border-slate-50 hover:text-indigo-600">
                    <span class="font-bold">{{ r.customer }} <span class="font-mono text-slate-400">{{ r.number }}</span></span>
                    <span class="font-mono" :class="r.ends ? 'text-amber-600' : 'text-slate-600'">{{ r.date }} · {{ r.ends ? 'ينتهي' : money(r.amount) }}</span>
                </Link>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex flex-wrap gap-2 text-xs">
            <input v-model="f.search" @input="apply" placeholder="بحث برقم الاشتراك أو اسم العميل..." class="flex-1 min-w-[12rem] rounded-xl border-slate-200 text-xs" />
            <select v-model="f.status" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option></select>
            <select v-model="f.plan" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">كل الخطط</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr>
                    <th class="px-4 py-2 text-start">الاشتراك</th><th class="px-4 py-2 text-start">العميل</th><th class="px-4 py-2 text-start">الخطة</th><th class="px-4 py-2 text-start">القيمة / فترة</th>
                    <th class="px-4 py-2 text-start">التجديد القادم</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 w-16"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in subscriptions.data" :key="s.id" class="border-t border-slate-100" :data-subscription="s.number">
                        <td class="px-4 py-2 font-mono font-bold text-indigo-600">{{ s.number }}</td>
                        <td class="px-4 py-2 font-bold">{{ s.customer?.name }}</td>
                        <td class="px-4 py-2">{{ s.plan?.name }}<span v-if="s.pending_plan_id" class="block text-3xs text-amber-600">تغيير الخطة عند التجديد</span></td>
                        <td class="px-4 py-2 font-mono">{{ money(Number(s.quantity) * Number(s.unit_price) * (1 + Number(s.tax_rate) / 100)) }}</td>
                        <td class="px-4 py-2 font-mono">{{ s.recurring?.next_run_date?.slice(0, 10) || '—' }}<span v-if="s.cancel_at_period_end" class="block text-3xs text-amber-600">يُلغى في نهاية الفترة</span></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full border text-[11px] font-bold" :class="tone[s.status]">{{ statuses[s.status] }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('sales.subscriptions.show', s.id)" /></td>
                    </tr>
                    <tr v-if="!subscriptions.data.length"><td colspan="7" class="py-10 text-center text-slate-400">لا توجد اشتراكات.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="subscriptions" />
    </AppLayout>
</template>

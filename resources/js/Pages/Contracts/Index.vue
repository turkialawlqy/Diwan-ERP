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

const props = defineProps({ contracts: Object, filters: Object, stats: Object, statuses: Object, kinds: Object });
const f = reactive({ ...props.filters });
const apply = () => router.get(route('contracts.index'), { search: f.search || undefined, status: f.status || undefined, kind: f.kind || undefined, side: f.side || undefined, expiring: f.expiring ? 1 : undefined }, { preserveState: true, replace: true });
const tone = { draft: 'bg-slate-100 text-slate-600', pending_approval: 'bg-amber-50 text-amber-700', rejected: 'bg-rose-50 text-rose-700', approved: 'bg-sky-50 text-sky-700', active: 'bg-emerald-50 text-emerald-700', expired: 'bg-orange-50 text-orange-700', terminated: 'bg-rose-50 text-rose-700', renewed: 'bg-violet-50 text-violet-700' };
const days = (c) => (c.status === 'active' && c.end_date ? Math.ceil((new Date(c.end_date) - new Date()) / 86400000) : null);
</script>

<template>
    <AppLayout>
        <Head title="العقود" />
        <PageHeader title="العقود" subtitle="عقود العملاء والموردين بنصوصها وإصداراتها والتزاماتها" :crumbs="[{ label: 'العقود' }]">
            <template #actions>
                <Link v-if="$can('contracts.templates.view')" :href="route('contracts.templates.index')" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 text-xs font-bold">النماذج</Link>
                <Link v-if="$can('contracts.contracts.create')" :href="route('contracts.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-contract><PlusIcon class="w-4 h-4" /> عقد جديد</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" data-contract-stats>
            <StatCard label="عقود سارية" :value="stats.active" tone="emerald" />
            <StatCard label="تنتهي خلال 30 يومًا" :value="stats.expiring" :tone="stats.expiring ? 'amber' : 'slate'" />
            <StatCard label="بانتظار الاعتماد" :value="stats.pending" :tone="stats.pending ? 'indigo' : 'slate'" />
            <StatCard label="التزامات متأخرة" :value="stats.overdue_obligations" :tone="stats.overdue_obligations ? 'rose' : 'slate'" />
            <StatCard label="قيمة العقود السارية" :value="money(stats.value)" />
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex flex-wrap gap-2 text-xs">
            <input v-model="f.search" @input="apply" placeholder="بحث برقم العقد أو العنوان أو الطرف..." class="flex-1 min-w-[12rem] rounded-xl border-slate-200 text-xs" />
            <select v-model="f.status" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.kind" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">كل الأنواع</option><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.side" @change="apply" class="rounded-xl border-slate-200 text-xs"><option value="">العملاء والموردون</option><option value="customer">عقود العملاء</option><option value="supplier">عقود الموردين</option></select>
            <label class="flex items-center gap-1 font-bold text-slate-600"><input type="checkbox" v-model="f.expiring" @change="apply" class="rounded" /> تنتهي قريبًا</label>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr>
                    <th class="px-4 py-2 text-start">العقد</th><th class="px-4 py-2 text-start">الطرف</th><th class="px-4 py-2 text-start">النوع</th><th class="px-4 py-2 text-start">القيمة</th>
                    <th class="px-4 py-2 text-start">المدة</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 w-20"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in contracts.data" :key="c.id" class="border-t border-slate-100" :data-contract="c.number">
                        <td class="px-4 py-2"><Link :href="route('contracts.show', c.id)" class="font-mono font-bold text-indigo-600">{{ c.number }}</Link><span class="block font-bold text-slate-800">{{ c.title }}</span></td>
                        <td class="px-4 py-2">{{ c.party_type === 'supplier' ? c.supplier?.name : c.customer?.name }}<span class="block text-3xs text-slate-400">{{ c.party_type === 'supplier' ? 'مورد' : 'عميل' }}</span></td>
                        <td class="px-4 py-2">{{ kinds[c.kind] }}</td>
                        <td class="px-4 py-2 font-mono">{{ money(c.value) }}</td>
                        <td class="px-4 py-2 font-mono">{{ c.start_date?.slice(0, 10) }} → {{ c.end_date?.slice(0, 10) || 'مفتوح' }}<span v-if="days(c) !== null && days(c) <= 30" class="block text-3xs font-bold" :class="days(c) <= 7 ? 'text-rose-600' : 'text-amber-600'">يبقى {{ days(c) }} يوم</span></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-bold" :class="tone[c.status]">{{ statuses[c.status] }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('contracts.show', c.id)" :edit="$can('contracts.contracts.edit') && ['draft', 'rejected'].includes(c.status) ? route('contracts.edit', c.id) : null" :remove="$can('contracts.contracts.delete') && ['draft', 'rejected'].includes(c.status) ? route('contracts.destroy', c.id) : null" :confirm="`حذف العقد ${c.number}؟`" /></td>
                    </tr>
                    <tr v-if="!contracts.data.length"><td colspan="7" class="py-10 text-center text-slate-400">لا توجد عقود.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="contracts" />
    </AppLayout>
</template>

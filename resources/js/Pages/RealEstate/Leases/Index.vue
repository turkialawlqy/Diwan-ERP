<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

const props = defineProps({ leases: Object, filters: Object, statuses: Object, cycles: Object });
const f = reactive({ ...props.filters });
const go = () => router.get(route('real-estate.leases.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
</script>

<template>
    <AppLayout>
        <Head title="عقود الإيجار" />
        <PageHeader title="عقود الإيجار" :crumbs="[{ label: 'العقارات' }, { label: 'عقود الإيجار' }]">
            <template #actions><Link v-if="$can('real_estate.leases.create')" :href="route('real-estate.leases.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-lease><PlusIcon class="w-4 h-4" /> عقد إيجار جديد</Link></template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex gap-2 text-xs"><input v-model="f.search" @keyup.enter="go" placeholder="رقم العقد أو المستأجر أو الوحدة" class="rounded-xl border-slate-200 text-xs w-64" />
            <select v-model="f.status" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">الوحدة</th><th class="px-4 py-2 text-start">المستأجر</th><th class="px-4 py-2 text-start">المدة</th><th class="px-4 py-2 text-start">الإيجار</th><th class="px-4 py-2 text-start">الحالة</th></tr></thead>
            <tbody>
                <tr v-for="l in leases.data" :key="l.id" class="border-t border-slate-100" :data-lease="l.number">
                    <td class="px-4 py-2"><Link :href="route('real-estate.leases.show', l.id)" class="font-mono font-bold text-indigo-600">{{ l.number }}</Link></td><td class="px-4 py-2">{{ l.unit?.property?.name }} — {{ l.unit?.unit_number }}</td>
                    <td class="px-4 py-2 font-bold">{{ l.customer?.name }}</td><td class="px-4 py-2 font-mono">{{ d(l.start_date) }} → {{ d(l.end_date) }}</td><td class="px-4 py-2 font-mono">{{ money(l.rent_amount) }} / {{ cycles[l.cycle] }}</td>
                    <td class="px-4 py-2 font-bold" :class="l.status === 'active' ? 'text-emerald-700' : 'text-slate-500'">{{ statuses[l.status] }}</td>
                </tr>
                <tr v-if="!leases.data.length"><td colspan="6" class="py-10 text-center text-slate-400">لا عقود.</td></tr>
            </tbody></table></div>
        <Pagination :paginator="leases" />
    </AppLayout>
</template>

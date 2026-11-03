<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, contracts: Object });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
</script>

<template>
    <Head title="العقود" />
    <PortalLayout :portal="portal" active="contracts">
        <h1 class="text-lg font-black text-slate-900 mb-3">العقود</h1>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">العقد</th><th class="px-4 py-2 text-start">المدة</th><th class="px-4 py-2 text-start">القيمة</th><th class="px-4 py-2 text-start">قبولك</th></tr></thead>
                <tbody>
                    <tr v-for="c in contracts.data" :key="c.id" class="border-t border-slate-100" :data-contract="c.number">
                        <td class="px-4 py-2 font-mono font-bold">{{ c.number }}</td>
                        <td class="px-4 py-2"><Link :href="r('customer-portal.contracts.show', { contract: c.id })" class="font-bold text-indigo-600">{{ c.title }}</Link></td>
                        <td class="px-4 py-2 font-mono">{{ c.start_date?.slice(0, 10) }} → {{ c.end_date?.slice(0, 10) || '—' }}</td><td class="px-4 py-2 font-mono">{{ c.value ? money(c.value) : '—' }}</td>
                        <td class="px-4 py-2"><span v-if="!c.requires_acceptance" class="text-slate-400">غير مطلوب</span><span v-else-if="c.accepted_current" class="text-emerald-600 font-bold">تم القبول</span><span v-else class="text-amber-600 font-bold">بانتظار قبولك</span></td>
                    </tr>
                    <tr v-if="!contracts.data.length"><td colspan="5" class="py-8 text-center text-slate-400">لا عقود مشاركة معك.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="contracts" />
    </PortalLayout>
</template>

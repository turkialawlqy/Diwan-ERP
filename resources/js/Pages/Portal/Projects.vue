<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money } from '@/utils/format';

const props = defineProps({ portal: Object, projects: Object });
const r = (n, p = {}) => route(n, { company: props.portal.company.id, ...p });
</script>

<template>
    <Head title="المشاريع" />
    <PortalLayout :portal="portal" active="projects">
        <h1 class="text-lg font-black text-slate-900 mb-3">المشاريع</h1>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرمز</th><th class="px-4 py-2 text-start">المشروع</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 text-start">المستخلصات</th><th class="px-4 py-2 text-start">المفوتر تراكميًا</th></tr></thead>
                <tbody>
                    <tr v-for="p in projects.data" :key="p.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono font-bold">{{ p.code }}</td>
                        <td class="px-4 py-2"><Link :href="r('customer-portal.projects.show', { project: p.id })" class="font-bold text-indigo-600">{{ p.name }}</Link></td>
                        <td class="px-4 py-2">{{ p.status }}</td><td class="px-4 py-2 font-mono">{{ p.billings }}</td><td class="px-4 py-2 font-mono">{{ money(p.billed) }}</td>
                    </tr>
                    <tr v-if="!projects.data.length"><td colspan="5" class="py-8 text-center text-slate-400">لا مشاريع.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="projects" />
    </PortalLayout>
</template>

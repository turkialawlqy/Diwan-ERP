<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { money } from '@/utils/format';

defineProps({ portal: Object, project: Object, billings: Array });
</script>

<template>
    <Head :title="project.name" />
    <PortalLayout :portal="portal" active="projects">
        <div class="mb-3"><h1 class="text-lg font-black text-slate-900">{{ project.name }}</h1><p class="text-xs text-slate-500"><span class="font-mono">{{ project.code }}</span> · {{ project.status }} <span v-if="project.location">· {{ project.location }}</span></p></div>
        <p v-if="project.description" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs mb-3 whitespace-pre-line">{{ project.description }}</p>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-billings>
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">{{ $t('por_portal_projectshow.approved_certificates') }}</h2>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('por_portal_projectshow.number') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_projectshow.period') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_projectshow.current') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_projectshow.cumulative') }}</th><th class="px-4 py-2 text-start">{{ $t('por_portal_projectshow.net') }}</th><th class="px-4 py-2"></th></tr></thead>
                <tbody>
                    <tr v-for="b in billings" :key="b.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono font-bold">{{ b.number }}</td><td class="px-4 py-2 font-mono">{{ b.period_from?.slice(0, 10) }} → {{ b.period_to?.slice(0, 10) }}</td>
                        <td class="px-4 py-2 font-mono">{{ money(b.current_value) }}</td><td class="px-4 py-2 font-mono">{{ money(b.cumulative_value) }}</td><td class="px-4 py-2 font-mono font-bold">{{ money(b.net_payable) }}</td>
                        <td class="px-4 py-2"><a :href="b.pdf_url" target="_blank" class="text-indigo-600 font-bold">PDF</a></td>
                    </tr>
                    <tr v-if="!billings.length"><td colspan="6" class="py-6 text-center text-slate-400">{{ $t('por_portal_projectshow.no_approved_certificates') }}</td></tr>
                </tbody>
            </table>
        </div>
    </PortalLayout>
</template>

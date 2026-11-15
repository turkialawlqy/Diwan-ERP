<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';

const props = defineProps({ lines: Object, statuses: Object, status: String, central: Boolean });
const go = (e) => router.get(route('hr.admin-requests.inbox'), e.target.value ? { status: e.target.value } : {}, { preserveState: true, replace: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_adminrequests_inbox.notification_tasks')" />
        <PageHeader :title="$t('hr_adminrequests_inbox.notification_tasks')" :subtitle="central ? $t('hr2_hr_adminrequests_inbox.all_current_lines') : $t('hr2_hr_adminrequests_inbox.lines_of_your_department_s_emplo')" :crumbs="[{ label: $t('hr2_hr_adminrequests_inbox.administrative_requests') }, { label: $t('hr2_hr_adminrequests_inbox.notification_tasks') }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 text-xs"><select :value="status || ''" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('hr_adminrequests_inbox.in_progress_only') }}</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_inbox.request') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_inbox.employee') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_inbox.department') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_inbox.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="l in lines.data" :key="l.id" class="border-t border-slate-100" :data-line="l.employee.name">
                        <td class="px-4 py-2"><Link :href="route('hr.admin-requests.show', l.admin_request_id)" class="font-mono font-bold text-indigo-600">{{ l.request.number }}</Link><span class="block">{{ l.request.title }}</span></td>
                        <td class="px-4 py-2 font-bold">{{ l.employee.name }}</td><td class="px-4 py-2">{{ l.department?.name || '—' }}</td><td class="px-4 py-2 font-bold">{{ statuses[l.status] }}</td>
                    </tr>
                    <tr v-if="!lines.data.length"><td colspan="4" class="py-10 text-center text-slate-400">{{ $t('hr_adminrequests_inbox.no_tasks') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="lines" />
    </AppLayout>
</template>

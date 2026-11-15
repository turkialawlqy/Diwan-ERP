<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ requests: Object, filters: Object, statuses: Object, departments: Array, employees: Array });
const f = reactive({ ...props.filters });
const go = () => router.get(route('hr.admin-requests.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const tone = { draft: 'text-slate-500', new: 'text-sky-700', processing: 'text-amber-700', completed: 'text-emerald-700', completed_partial: 'text-rose-600' };
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_adminrequests_index.administrative_requests')" />
        <PageHeader :title="$t('hr_adminrequests_index.administrative_requests')" :subtitle="$t('hr_adminrequests_index.summon_employees_to_attend_and_f')" :crumbs="[{ label: $t('hr2_hr_adminrequests_index.employee_affairs') }, { label: $t('hr2_hr_adminrequests_index.administrative_requests') }]">
            <template #actions>
                <Link :href="route('hr.admin-requests.inbox')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('hr_adminrequests_index.notification_tasks') }}</Link>
                <Link v-if="$can('hr.admin_requests.create')" :href="route('hr.admin-requests.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-request><PlusIcon class="w-4 h-4" /> {{ $t('hr_adminrequests_index.new_administrative_request') }}</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex flex-wrap items-end gap-2 text-xs">
            <input v-model="f.search" @keyup.enter="go" :placeholder="$t('hr_adminrequests_index.number_or_title')" class="rounded-xl border-slate-200 text-xs w-48" />
            <select v-model="f.status" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('hr_adminrequests_index.all_statuses') }}</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.department" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('hr_adminrequests_index.all_departments') }}</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
            <select v-model="f.employee" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('hr_adminrequests_index.all_employees') }}</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_index.number') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_index.title') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_index.appointment') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_index.employees') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_adminrequests_index.status') }}</th><th class="px-4 py-2 w-24"></th></tr></thead>
                <tbody>
                    <tr v-for="r in requests.data" :key="r.id" class="border-t border-slate-100" :data-request="r.number">
                        <td class="px-4 py-2"><Link :href="route('hr.admin-requests.show', r.id)" class="font-mono font-bold text-indigo-600">{{ r.number }}</Link></td>
                        <td class="px-4 py-2 font-bold">{{ r.title }}</td><td class="px-4 py-2 font-mono">{{ when(r.attend_at) }}</td><td class="px-4 py-2">{{ r.lines_count }}</td>
                        <td class="px-4 py-2 font-bold" :class="tone[r.status]">{{ statuses[r.status] }}</td>
                        <td class="px-4 py-2 text-end"><RowActions :view="route('hr.admin-requests.show', r.id)" :edit="r.status === 'draft' && $can('hr.admin_requests.edit') ? route('hr.admin-requests.edit', r.id) : null" :remove="r.status === 'draft' && $can('hr.admin_requests.delete') ? route('hr.admin-requests.destroy', r.id) : null" confirm="حذف هذه المسودة؟" /></td>
                    </tr>
                    <tr v-if="!requests.data.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('hr_adminrequests_index.no_administrative_requests') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="requests" />
    </AppLayout>
</template>

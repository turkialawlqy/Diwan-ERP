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
        <Head title="مهام التبليغ" />
        <PageHeader title="مهام التبليغ" :subtitle="central ? 'كل الأسطر الجارية' : 'أسطر موظفي إدارتك أو المسندة إليك'" :crumbs="[{ label: 'الطلبات الإدارية' }, { label: 'مهام التبليغ' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 text-xs"><select :value="status || ''" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">الجارية فقط</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الطلب</th><th class="px-4 py-2 text-start">الموظف</th><th class="px-4 py-2 text-start">الإدارة</th><th class="px-4 py-2 text-start">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="l in lines.data" :key="l.id" class="border-t border-slate-100" :data-line="l.employee.name">
                        <td class="px-4 py-2"><Link :href="route('hr.admin-requests.show', l.admin_request_id)" class="font-mono font-bold text-indigo-600">{{ l.request.number }}</Link><span class="block">{{ l.request.title }}</span></td>
                        <td class="px-4 py-2 font-bold">{{ l.employee.name }}</td><td class="px-4 py-2">{{ l.department?.name || '—' }}</td><td class="px-4 py-2 font-bold">{{ statuses[l.status] }}</td>
                    </tr>
                    <tr v-if="!lines.data.length"><td colspan="4" class="py-10 text-center text-slate-400">لا مهام.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="lines" />
    </AppLayout>
</template>

<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { HomeIcon, ChevronRightIcon, ChevronDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    logs: Object,
    users: Array,
    filters: Object,
});

const filters = ref({
    user_id: props.filters?.user_id ?? '',
    event: props.filters?.event ?? '',
    type: props.filters?.type ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const expanded = ref(null);

const apply = () => {
    const query = Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null));
    router.get(route('audit.index'), query, { preserveState: true, preserveScroll: true });
};

const { t } = useI18n();
const eventLabels = computed(() => ({
    created: t('x_auditlog_index.create'),
    updated: t('x_auditlog_index.edit'),
    deleted: t('x_auditlog_index.delete'),
    login: t('x_auditlog_index.sign_in'),
    login_failed: t('x_auditlog_index.failed_sign_in_attempt'),
    'report.exported': t('repgap.report_exported'),
    role_created: t('x_auditlog_index.create_a_role'),
    role_renamed: t('x_auditlog_index.rename_a_role'),
    role_deleted: t('x_auditlog_index.delete_a_role'),
    role_permissions_updated: t('x_auditlog_index.edit_a_role_s_permissions'),
    user_created: t('x_auditlog_index.create_a_user'),
    user_updated: t('x_auditlog_index.edit_a_user'),
    user_deleted: t('x_auditlog_index.delete_a_user'),
    user_permissions_updated: t('x_auditlog_index.edit_a_user_s_permissions'),
}));

const eventClass = (event) => {
    if (event.includes('deleted') || event === 'login_failed') return 'bg-rose-50 text-rose-700 border-rose-200';
    if (event.includes('created') || event === 'login') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    return 'bg-amber-50 text-amber-700 border-amber-200';
};

const format = (value) => (typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '—'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_auditlog_index.audit_log')" />

        <div class="space-y-5">
            <PageHeader :title="$t('nav.audit_log')" :crumbs="[{ label: $t('nav.audit_log') }]" />

            <!-- Filters -->
            <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 text-xs">
                <select v-model="filters.user_id" class="rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('x_auditlog_index.all_users') }}</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.event" class="rounded-xl border-slate-200 text-xs">
                    <option value="">{{ $t('x_auditlog_index.all_actions') }}</option>
                    <option v-for="(label, key) in eventLabels" :key="key" :value="key">{{ label }}</option>
                </select>
                <input v-model="filters.type" :placeholder="$t('x_auditlog_index.record_type_e_g_invoice')" class="rounded-xl border-slate-200 text-xs" />
                <DatePicker v-model="filters.from" class="rounded-xl border-slate-200 text-xs" />
                <DatePicker v-model="filters.to" class="rounded-xl border-slate-200 text-xs" />
                <button type="submit" class="rounded-xl bg-indigo-600 text-white font-bold px-4 py-2 hover:bg-indigo-700">{{ $t('common.filter') }}</button>
            </form>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-start px-4 py-3">{{ $t('x_auditlog_index.date') }}</th>
                                <th class="text-start px-4 py-3">{{ $t('x_auditlog_index.user') }}</th>
                                <th class="text-start px-4 py-3">{{ $t('x_auditlog_index.operation') }}</th>
                                <th class="text-start px-4 py-3">{{ $t('x_auditlog_index.record') }}</th>
                                <th class="text-start px-4 py-3">IP</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="log in logs.data" :key="log.id">
                                <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td class="px-4 py-3 font-mono text-slate-600" dir="ltr">{{ log.created_at }}</td>
                                    <td class="px-4 py-3 text-slate-700 font-semibold">{{ log.user ?? $t('x_auditlog_index.system') }}</td>
                                    <td class="px-4 py-3">
                                        <span :class="eventClass(log.event)" class="px-2 py-0.5 rounded-full border font-bold text-[11px]">{{ eventLabels[log.event] ?? log.event }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ log.type ? `${log.type} #${log.subject_id}` : '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ log.ip_address ?? '—' }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <button v-if="log.old_values || log.new_values" @click="expanded = expanded === log.id ? null : log.id" class="text-slate-400 hover:text-indigo-600">
                                            <ChevronDownIcon :class="[expanded === log.id ? 'rotate-180' : '', 'w-4 h-4 transition-transform']" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expanded === log.id" class="bg-slate-50/70">
                                    <td colspan="6" class="px-4 py-3">
                                        <div class="overflow-x-auto"><table class="w-full text-[11px]">
                                            <thead class="text-slate-500">
                                                <tr><th class="text-start py-1">{{ $t('x_auditlog_index.field') }}</th><th class="text-start py-1">{{ $t('x_auditlog_index.by') }}</th><th class="text-start py-1">{{ $t('x_auditlog_index.after') }}</th></tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="key in [...new Set([...Object.keys(log.old_values ?? {}), ...Object.keys(log.new_values ?? {})])]" :key="key" class="border-t border-slate-200/60">
                                                    <td class="py-1 font-mono text-slate-600">{{ key }}</td>
                                                    <td class="py-1 text-rose-700 break-all">{{ format(log.old_values?.[key]) }}</td>
                                                    <td class="py-1 text-emerald-700 break-all">{{ format(log.new_values?.[key]) }}</td>
                                                </tr>
                                            </tbody>
                                        </table></div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!logs.data.length">
                                <td colspan="6" class="py-10 text-center text-slate-400">{{ $t('common.no_data') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="logs.last_page > 1" class="flex items-center justify-between p-4 border-t border-slate-100 text-xs text-slate-500">
                    <span>{{ $t('x_auditlog_index.page_n_of_n', { n0: logs.current_page, n1: logs.last_page }) }}</span>
                    <div class="flex gap-2">
                        <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" preserve-scroll class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">{{ $t('x_auditlog_index.previous') }}</Link>
                        <Link v-if="logs.next_page_url" :href="logs.next_page_url" preserve-scroll class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">{{ $t('x_auditlog_index.next') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

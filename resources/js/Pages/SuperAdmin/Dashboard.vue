<script setup>
import { useI18n } from 'vue-i18n';
import { ask } from '@/composables/useDialog';
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { 
    BuildingOfficeIcon, 
    UsersIcon, 
    CheckCircleIcon, 
    ClockIcon, 
    ArrowRightOnRectangleIcon,
    MagnifyingGlassIcon,
    ArrowTopRightOnSquareIcon
} from '@heroicons/vue/24/outline';
import { timeZone } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({
    companies: Object,
    stats: Object,
    plans: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');

const handleFilter = () => {
    router.get(route('super-admin.dashboard'), {
        search: search.value,
        status: status.value,
    }, { preserveState: true, replace: true });
};

const impersonate = async (companyId) => {
    const reason = await ask(t('z_superadmin_dashboard.reason_for_accessing_the_company'), { title: t('z_superadmin_dashboard.sign_in_as_the_company_manager'), type: 'textarea', required: true, minLength: 5 });
    if (!reason) return;
    router.post(route('super-admin.impersonate.enter', companyId), { reason });
};
</script>

<template>
    <SuperAdminLayout>
        <Head :title="$t('z_superadmin_dashboard.platform_dashboard_managing_comp')" />

        <!-- Header Title -->
        <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">{{ $t('z_superadmin_dashboard.managing_subscribed_companies') }}</h1>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">
                    {{ $t('z_superadmin_dashboard.you_can_browse_all_subscribed_co') }}
                </p>
            </div>
        </div>

        <!-- Global Stats Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-slate-500">{{ $t('z_superadmin_dashboard.total_companies') }}</span>
                    <p class="text-2xl font-black text-slate-900 mt-1">{{ stats.total_companies }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <BuildingOfficeIcon class="w-6 h-6" />
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-slate-500">{{ $t('z_superadmin_dashboard.active_companies') }}</span>
                    <p class="text-2xl font-black text-emerald-600 mt-1">{{ stats.active_companies }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <CheckCircleIcon class="w-6 h-6" />
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-slate-500">{{ $t('z_superadmin_dashboard.trial_period') }}</span>
                    <p class="text-2xl font-black text-amber-600 mt-1">{{ stats.trial_companies }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <ClockIcon class="w-6 h-6" />
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-slate-500">{{ $t('z_superadmin_dashboard.total_users') }}</span>
                    <p class="text-2xl font-black text-purple-600 mt-1">{{ stats.total_users }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                    <UsersIcon class="w-6 h-6" />
                </div>
            </div>
        </div>

        <!-- Filters & Search Bar -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs mb-6 flex flex-col sm:flex-row gap-4 justify-between items-center">
            <div class="relative w-full sm:w-80">
                <span class="absolute inset-y-0 start-0 ps-3 flex items-center pointer-events-none text-slate-400">
                    <MagnifyingGlassIcon class="w-4 h-4" />
                </span>
                <input
                    v-model="search"
                    @input="handleFilter"
                    type="search"
                    :placeholder="$t('z_superadmin_dashboard.search_by_name_subdomain_or_emai')"
                    class="w-full bg-slate-50 border border-slate-200 text-xs rounded-xl ps-9 pe-4 py-2.5 focus:bg-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                />
            </div>

            <div class="flex items-center gap-3 w-full sm:w-auto">
                <select
                    v-model="status"
                    @change="handleFilter"
                    class="bg-slate-50 border border-slate-200 text-xs rounded-xl py-2.5 focus:ring-purple-500 focus:border-purple-500 font-medium text-slate-700"
                >
                    <option value="">{{ $t('z_superadmin_dashboard.all_statuses') }}</option>
                    <option value="active">{{ $t('z_superadmin_dashboard.active') }}</option>
                    <option value="trial">{{ $t('z_superadmin_dashboard.trial_period_2') }}</option>
                    <option value="suspended">{{ $t('z_superadmin_dashboard.pending') }}</option>
                </select>
            </div>
        </div>

        <!-- Companies Table -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs">
                    <thead class="bg-slate-50/75 border-b border-slate-200 text-slate-500 font-bold">
                        <tr>
                            <th class="px-6 py-3.5 text-start">{{ $t('z_superadmin_dashboard.company') }}</th>
                            <th class="px-6 py-3.5 text-start">{{ $t('z_superadmin_dashboard.subdomain') }}</th>
                            <th class="px-6 py-3.5 text-start">{{ $t('z_superadmin_dashboard.plan') }}</th>
                            <th class="px-6 py-3.5 text-start">{{ $t('z_superadmin_dashboard.status') }}</th>
                            <th class="px-6 py-3.5 text-start">{{ $t('z_superadmin_dashboard.users') }}</th>
                            <th class="px-6 py-3.5 text-start">{{ $t('z_superadmin_dashboard.join_date') }}</th>
                            <th class="px-6 py-3.5 text-center">{{ $t('z_superadmin_dashboard.sign_in_and_control') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr 
                            v-for="company in companies.data" 
                            :key="company.id"
                            class="hover:bg-slate-50/80 transition-colors"
                        >
                            <!-- Company Name & Phone -->
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-sm flex-shrink-0">
                                        {{ company.name.charAt(0) }}
                                    </div>
                                    <div>
                                        <Link :href="route('super-admin.companies.show', company.id)" class="font-bold text-slate-900 text-xs sm:text-sm hover:text-indigo-600">{{ company.name }}</Link>
                                        <p class="text-[11px] text-slate-400 font-mono" dir="ltr">{{ company.email || company.phone }}</p>
                                    </div>
                                </div>
                            </td>

                            <!-- Subdomain -->
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center gap-1 font-mono text-xs font-bold text-indigo-600 bg-indigo-50/70 px-2.5 py-1 rounded-lg border border-indigo-100" dir="ltr">
                                    {{ company.subdomain }}.{{ $page.props.brand?.domain }}
                                </span>
                            </td>

                            <!-- Plan -->
                            <td class="px-6 py-4 font-medium text-slate-700">
                                {{ company.plan ? company.plan.name_ar : $t('z_superadmin_dashboard.no_plan') }}
                            </td>

                            <!-- Status Badge -->
                            <td class="px-6 py-4">
                                <StatusBadge :status="company.status" />
                            </td>

                            <!-- Users count -->
                            <td class="px-6 py-4 font-bold text-slate-700">
                                {{ company.users_count }}
                            </td>

                            <!-- Created date -->
                            <td class="px-6 py-4 text-slate-500 font-mono text-[11px]" dir="ltr">
                                {{ new Date(company.created_at).toLocaleDateString('en-CA', { timeZone: timeZone() }) }}
                            </td>

                            <!-- Impersonate Action Button -->
                            <td class="px-6 py-4 text-center">
                                <button
                                    @click="impersonate(company.id)"
                                    class="inline-flex items-center gap-1.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 font-bold px-3 py-1.5 rounded-xl shadow-xs hover:shadow text-xs transition active:scale-95"
                                    :title="$t('z_superadmin_dashboard.sign_in_to_the_system_to_solve_p')"
                                >
                                    <ArrowRightOnRectangleIcon class="w-4 h-4" />
                                    <span>{{ $t('z_superadmin_dashboard.sign_in_as_super_admin') }}</span>
                                </button>
                            </td>
                        </tr>

                        <tr v-if="!companies.data || companies.data.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                                {{ $t('z_superadmin_dashboard.no_companies_match_the_search') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </SuperAdminLayout>
</template>

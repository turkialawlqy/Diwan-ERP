<script setup>
import { useI18n } from 'vue-i18n';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PermissionMatrix from '@/Components/PermissionMatrix.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    UserGroupIcon,
    ShieldCheckIcon,
    XMarkIcon,
    PencilSquareIcon,
    TrashIcon,
    BuildingOfficeIcon,
    CheckCircleIcon,
    KeyIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({
    users: Object,
    branches: Array,
    roles: Array,
    stats: Object,
    matrix: Object,
});

// Extra (direct) permissions for a single user, on top of their role.
const permissionsUser = ref(null);
const permissionsForm = useForm({ permissions: [] });
const openPermissions = (u) => {
    permissionsUser.value = u;
    permissionsForm.permissions = [...(u.direct_permissions ?? [])];
    permissionsForm.clearErrors();
};
const savePermissions = () => {
    permissionsForm.put(route('users.permissions', permissionsUser.value.id), {
        preserveScroll: true,
        onSuccess: () => { permissionsUser.value = null; },
    });
};

const userIsOwner = (u) => !!props.roles?.find((r) => r.name === u.roles?.[0]?.name)?.is_owner;

const deleteUser = (id, name) => {
    if (confirm(t('x_users_index.are_you_sure_you_want_to_delete', { n0: name }))) {
        router.delete(route('users.destroy', id));
    }
};

const getRoleBadge = (roleName) => {
    const role = props.roles?.find((r) => r.name === roleName);
    if (role?.is_owner) {
        return { label: role.label, class: 'bg-rose-50 text-rose-700 border-rose-200' };
    }
    const rolesMap = {
        admin: { label: t('x_users_index.system_administrator'), class: 'bg-rose-50 text-rose-700 border-rose-200' },
        accountant: { label: t('x_users_index.accountant'), class: 'bg-blue-50 text-blue-700 border-blue-200' },
        sales: { label: t('x_users_index.sales_officer'), class: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
        cashier: { label: t('x_users_index.pos_cashier'), class: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
        employee: { label: t('x_users_index.employee'), class: 'bg-slate-100 text-slate-700 border-slate-200' },
    };
    const style = rolesMap[roleName]?.class || 'bg-slate-100 text-slate-700 border-slate-200';
    return { label: role?.label || rolesMap[roleName]?.label || roleName, class: style };
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_users_index.user_management')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('x_users_index.users')" :crumbs="[{ label: $t('x_users_index.users') }]">
<template #actions>
<Link 
                        :href="route('users.index')"
                        :title="$t('x_users_index.refresh_data')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
<Link
                        :href="route('roles.index')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs sm:text-sm shadow-xs transition-all"
                    >
                        <ShieldCheckIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('x_users_index.roles_and_permissions') }}</span>
                    </Link>
                    <Link v-if="$can('admin.users.create')" :href="route('users.create')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('x_users_index.add_a_user') }}</span>
                    </Link>
</template>
</PageHeader>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('x_users_index.total_registered_users') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-900">{{ stats?.total_users ?? 0 }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">{{ $t('x_users_index.active_users') }}</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ stats?.active_users ?? 0 }}</p>
                </div>
            </div>

            <!-- Users Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">{{ $t('x_users_index.user') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('x_users_index.email') }}</th>
                                <th class="py-4 px-6 text-start">{{ $t('x_users_index.branch') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('x_users_index.role_permission') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('x_users_index.status') }}</th>
                                <th class="py-4 px-6 text-center">{{ $t('x_users_index.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="u in users?.data || []" 
                                :key="u.id"
                                class="hover:bg-slate-50/60 transition"
                            >
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-xs">
                                            {{ u.name.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-900">{{ u.name }}</p>
                                            <p v-if="u.phone" class="text-[10px] text-slate-400 font-mono" dir="ltr">{{ u.phone }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-6 font-mono text-slate-600" dir="ltr">
                                    {{ u.email }}
                                </td>
                                <td class="py-4 px-6 text-slate-600">
                                    <span v-if="userIsOwner(u) || !(u.branches?.length || u.branch)" class="text-slate-400">{{ $t('x_users_index.all_branches') }}</span>
                                    <span v-else class="inline-flex items-start gap-1 font-semibold">
                                        <BuildingOfficeIcon class="w-3.5 h-3.5 text-slate-400 mt-0.5 shrink-0" />
                                        <span>{{ (u.branches?.length ? u.branches : [u.branch]).map((b) => b.name_ar).join($t('x_users_index.text')) }}</span>
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span 
                                        :class="getRoleBadge(u.roles?.[0]?.name).class"
                                        class="text-[11px] font-bold px-2.5 py-1 rounded-full border"
                                    >
                                        {{ getRoleBadge(u.roles?.[0]?.name).label }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <span 
                                        :class="u.status === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
                                        class="text-[11px] font-bold px-2.5 py-1 rounded-lg border"
                                    >
                                        {{ u.status === 'active' ? $t('x_users_index.active') : $t('x_users_index.disabled') }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button
                                            v-if="$can('admin.users.edit')"
                                            @click="openPermissions(u)"
                                            class="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition"
                                            :title="$t('permissions.extra_permissions')"
                                        >
                                            <KeyIcon class="w-4 h-4" />
                                        </button>
                                        <Link v-if="$can('admin.users.edit')" :href="route('users.edit', u.id)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition" :title="$t('x_users_index.edit')">
                                            <PencilSquareIcon class="w-4 h-4" />
                                        </Link>
                                        <button 
                                            v-if="$can('admin.users.delete')"
                                            @click="deleteUser(u.id, u.name)"
                                            class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                            :title="$t('x_users_index.delete')"
                                        >
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!users?.data || users.data.length === 0">
                                <td colspan="6" class="py-12 text-center text-slate-400">
                                    {{ $t('x_users_index.no_registered_users_yet') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="users?.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>{{ $t('x_users_index.total_n', { n0: users.total || 0 }) }}</span>
                        <span>{{ $t('x_users_index.per_page') }}</span>
                        <select 
                            :value="users.per_page"
                            @change="router.get(route('users.index'), { per_page: $event.target.value }, { preserveState: true })"
                            class="text-xs rounded-lg border-slate-200 py-1 pe-6 ps-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span>{{ $t('x_users_index.page_n_of_n', { n0: users.current_page, n1: users.last_page || 1 }) }}</span>
                        <div class="flex items-center gap-1">
                            <Link 
                                v-if="users.prev_page_url" 
                                :href="users.prev_page_url"
                                class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors"
                            >
                                <ChevronRightIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </Link>
                            <button 
                                v-else 
                                disabled 
                                class="p-1.5 rounded-lg border border-slate-100 text-slate-300 cursor-not-allowed"
                            >
                                <ChevronRightIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </button>
                            <span class="w-7 h-7 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 font-bold flex items-center justify-center">
                                {{ users.current_page }}
                            </span>
                            <Link 
                                v-if="users.next_page_url" 
                                :href="users.next_page_url"
                                class="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors"
                            >
                                <ChevronLeftIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </Link>
                            <button 
                                v-else 
                                disabled 
                                class="p-1.5 rounded-lg border border-slate-100 text-slate-300 cursor-not-allowed"
                            >
                                <ChevronLeftIcon class="w-3.5 h-3.5 rtl:rotate-180" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Extra permissions modal -->
        <div v-if="permissionsUser" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-5xl my-8">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <div>
                        <h3 class="font-bold text-slate-800">{{ $t('permissions.extra_permissions') }} — {{ permissionsUser.name }}</h3>
                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('permissions.extra_hint') }}</p>
                    </div>
                    <button @click="permissionsUser = null" class="text-slate-400 hover:text-slate-700"><XMarkIcon class="w-5 h-5" /></button>
                </div>
                <div class="p-5 max-h-[65vh] overflow-y-auto">
                    <div v-if="permissionsForm.errors.permissions" class="mb-3 text-xs text-rose-600 bg-rose-50 border border-rose-200 rounded-xl p-3">
                        {{ permissionsForm.errors.permissions }}
                    </div>
                    <PermissionMatrix v-model="permissionsForm.permissions" :matrix="matrix" />
                </div>
                <div class="flex justify-end gap-2 px-5 py-4 border-t border-slate-100">
                    <button @click="permissionsUser = null" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('common.cancel') }}</button>
                    <button @click="savePermissions" :disabled="permissionsForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700 disabled:opacity-50">{{ $t('common.save') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PermissionMatrix from '@/Components/PermissionMatrix.vue';
import {
    PlusIcon,
    HomeIcon,
    ChevronRightIcon,
    ShieldCheckIcon,
    UserGroupIcon,
    XMarkIcon,
    TrashIcon,
    DocumentDuplicateIcon,
    PencilSquareIcon,
    LockClosedIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    roles: Array,
    matrix: Object,
    selectedRoleId: Number,
    grantable: Array,
});

const selectedId = ref(props.selectedRoleId || props.roles[0]?.id);
const selectedRole = computed(() => props.roles.find((r) => r.id === selectedId.value) ?? props.roles[0]);

const permissionsForm = useForm({ permissions: [...(selectedRole.value?.permissions ?? [])] });
const dirty = computed(() => {
    const current = new Set(selectedRole.value?.permissions ?? []);
    return permissionsForm.permissions.length !== current.size || permissionsForm.permissions.some((p) => !current.has(p));
});

watch(selectedRole, (role) => {
    permissionsForm.permissions = [...(role?.permissions ?? [])];
    permissionsForm.clearErrors();
});

// Keep the local selection in sync after server redirects.
watch(() => props.roles, () => {
    if (!props.roles.some((r) => r.id === selectedId.value)) {
        selectedId.value = props.roles[0]?.id;
    }
    permissionsForm.permissions = [...(selectedRole.value?.permissions ?? [])];
});

const selectRole = (role) => {
    if (dirty.value && !confirm('لديك تغييرات غير محفوظة على صلاحيات هذا الدور. هل تريد تجاهلها؟')) {
        return;
    }
    selectedId.value = role.id;
};

const savePermissions = () => {
    permissionsForm.put(route('roles.permissions', selectedRole.value.id), { preserveScroll: true });
};

// Create / rename role
const isModalOpen = ref(false);
const editingRole = ref(null);
const roleForm = useForm({ name: '', copy_from: null });

const openCreate = () => {
    editingRole.value = null;
    roleForm.reset();
    roleForm.clearErrors();
    isModalOpen.value = true;
};

const openRename = (role) => {
    editingRole.value = role;
    roleForm.name = role.name;
    roleForm.copy_from = null;
    roleForm.clearErrors();
    isModalOpen.value = true;
};

const submitRole = () => {
    const options = { preserveScroll: true, onSuccess: () => { isModalOpen.value = false; } };
    if (editingRole.value) {
        roleForm.put(route('roles.update', editingRole.value.id), options);
    } else {
        roleForm.post(route('roles.store'), options);
    }
};

const duplicateRole = (role) => {
    router.post(route('roles.duplicate', role.id), {}, { preserveScroll: true });
};

const deleteRole = (role) => {
    if (confirm(`هل أنت متأكد من حذف الدور "${role.label}"؟`)) {
        router.delete(route('roles.destroy', role.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout>
        <Head title="الأدوار والصلاحيات" />

        <div class="space-y-5">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                    <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                        <HomeIcon class="w-4 h-4" />
                    </Link>
                    <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                    <span class="text-indigo-600 font-bold">{{ $t('nav.roles') }}</span>
                </div>

                <button
                    v-if="$can('admin.roles.create')"
                    @click="openCreate"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700 shadow-sm"
                >
                    <PlusIcon class="w-4 h-4" />
                    {{ $t('permissions.new_role') }}
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
                <!-- Roles list -->
                <div class="lg:col-span-1 space-y-2">
                    <button
                        v-for="role in roles"
                        :key="role.id"
                        @click="selectRole(role)"
                        :class="[
                            selectedRole?.id === role.id ? 'border-indigo-400 bg-indigo-50/60 ring-1 ring-indigo-200' : 'border-slate-200 bg-white hover:border-indigo-200',
                            'w-full text-start border rounded-2xl p-3 transition'
                        ]"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <component :is="role.is_owner ? LockClosedIcon : ShieldCheckIcon" :class="[role.is_owner ? 'text-rose-500' : 'text-indigo-500', 'w-4 h-4 flex-shrink-0']" />
                                <span class="font-bold text-sm text-slate-800 truncate">{{ role.label }}</span>
                            </div>
                            <span class="inline-flex items-center gap-1 text-[10px] text-slate-500 bg-slate-100 rounded-full px-2 py-0.5">
                                <UserGroupIcon class="w-3 h-3" />
                                {{ role.users_count }}
                            </span>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500">
                            {{ role.is_owner ? $t('permissions.owner_hint') : $t('permissions.count', { count: role.permissions.length }) }}
                        </div>
                    </button>
                </div>

                <!-- Matrix -->
                <div class="lg:col-span-3 space-y-4" v-if="selectedRole">
                    <div class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-800">{{ selectedRole.label }}</h2>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ selectedRole.is_owner ? $t('permissions.owner_locked') : $t('permissions.matrix_hint') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2" v-if="!selectedRole.is_owner">
                            <button v-if="$can('admin.roles.edit') && !selectedRole.is_system" @click="openRename(selectedRole)" class="p-2 rounded-xl border border-slate-200 text-slate-500 hover:text-indigo-600" :title="$t('common.edit')">
                                <PencilSquareIcon class="w-4 h-4" />
                            </button>
                            <button v-if="$can('admin.roles.create')" @click="duplicateRole(selectedRole)" class="p-2 rounded-xl border border-slate-200 text-slate-500 hover:text-indigo-600" :title="$t('permissions.duplicate')">
                                <DocumentDuplicateIcon class="w-4 h-4" />
                            </button>
                            <button v-if="$can('admin.roles.delete')" @click="deleteRole(selectedRole)" class="p-2 rounded-xl border border-slate-200 text-slate-500 hover:text-rose-600" :title="$t('common.delete')">
                                <TrashIcon class="w-4 h-4" />
                            </button>
                            <button
                                v-if="$can('admin.roles.edit')"
                                @click="savePermissions"
                                :disabled="!dirty || permissionsForm.processing"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 disabled:opacity-40"
                            >
                                <CheckIcon class="w-4 h-4" />
                                {{ $t('permissions.save') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="permissionsForm.errors.permissions" class="text-xs text-rose-600 bg-rose-50 border border-rose-200 rounded-xl p-3">
                        {{ permissionsForm.errors.permissions }}
                    </div>

                    <PermissionMatrix
                        v-model="permissionsForm.permissions"
                        :matrix="matrix"
                        :readonly="selectedRole.is_owner || !$can('admin.roles.edit')"
                        :grantable="grantable"
                    />
                </div>
            </div>
        </div>

        <!-- Create / rename modal -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-800">{{ editingRole ? $t('permissions.rename_role') : $t('permissions.new_role') }}</h3>
                    <button @click="isModalOpen = false" class="text-slate-400 hover:text-slate-700"><XMarkIcon class="w-5 h-5" /></button>
                </div>
                <form @submit.prevent="submitRole" class="p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('permissions.role_name') }}</label>
                        <input v-model="roleForm.name" type="text" required maxlength="50" class="w-full rounded-xl border-slate-200 text-sm focus:border-indigo-400 focus:ring-indigo-400" />
                        <p v-if="roleForm.errors.name" class="text-xs text-rose-600 mt-1">{{ roleForm.errors.name }}</p>
                    </div>
                    <div v-if="!editingRole">
                        <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('permissions.copy_from') }}</label>
                        <select v-model="roleForm.copy_from" class="w-full rounded-xl border-slate-200 text-sm focus:border-indigo-400 focus:ring-indigo-400">
                            <option :value="null">{{ $t('permissions.empty_role') }}</option>
                            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.label }}</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="isModalOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('common.cancel') }}</button>
                        <button type="submit" :disabled="roleForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700 disabled:opacity-50">{{ $t('common.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

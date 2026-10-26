<script setup>
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

const isModalOpen = ref(false);
const editingUser = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    phone: '',
    branch_id: '',
    branch_ids: [],
    role: 'sales',
    status: 'active',
});

const openAddModal = () => {
    editingUser.value = null;
    form.reset();
    form.branch_id = props.branches?.[0]?.id || '';
    form.branch_ids = form.branch_id ? [form.branch_id] : [];
    form.role = props.roles?.[0]?.name || 'sales';
    form.status = 'active';
    isModalOpen.value = true;
};

const openEditModal = (u) => {
    editingUser.value = u;
    form.name = u.name;
    form.email = u.email;
    form.password = '';
    form.phone = u.phone || '';
    form.branch_id = u.branch_id || '';
    form.branch_ids = u.branches?.length ? u.branches.map((b) => b.id) : (u.branch_id ? [u.branch_id] : []);
    form.role = u.roles?.[0]?.name || 'sales';
    form.status = u.status || 'active';
    isModalOpen.value = true;
};

// The owner role works in every branch; other users work in the ticked ones.
const isOwnerRole = computed(() => !!props.roles?.find((r) => r.name === form.role)?.is_owner);
const allowedBranches = computed(() => (props.branches || []).filter((b) => form.branch_ids.includes(b.id)));
watch(() => [...form.branch_ids], (ids) => {
    if (!ids.includes(form.branch_id)) form.branch_id = ids[0] || '';
});
const userIsOwner = (u) => !!props.roles?.find((r) => r.name === u.roles?.[0]?.name)?.is_owner;

const submitUser = () => {
    if (editingUser.value) {
        form.put(route('users.update', editingUser.value.id), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            }
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            }
        });
    }
};

const deleteUser = (id, name) => {
    if (confirm(`هل أنت متأكد من حذف المستخدم "${name}"؟`)) {
        router.delete(route('users.destroy', id));
    }
};

const getRoleBadge = (roleName) => {
    const role = props.roles?.find((r) => r.name === roleName);
    if (role?.is_owner) {
        return { label: role.label, class: 'bg-rose-50 text-rose-700 border-rose-200' };
    }
    const rolesMap = {
        admin: { label: 'مدير نظام', class: 'bg-rose-50 text-rose-700 border-rose-200' },
        accountant: { label: 'محاسب', class: 'bg-blue-50 text-blue-700 border-blue-200' },
        sales: { label: 'مسؤول مبيعات', class: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
        cashier: { label: 'كاشير POS', class: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
        employee: { label: 'موظف', class: 'bg-slate-100 text-slate-700 border-slate-200' },
    };
    const style = rolesMap[roleName]?.class || 'bg-slate-100 text-slate-700 border-slate-200';
    return { label: role?.label || rolesMap[roleName]?.label || roleName, class: style };
};
</script>

<template>
    <AppLayout>
        <Head title="إدارة المستخدمين" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Breadcrumbs -->
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                            <HomeIcon class="w-4 h-4" />
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">المستخدمين</span>
                    </div>
                    <Link 
                        :href="route('users.index')"
                        title="تحديث البيانات"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-xs transition-all"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('roles.index')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs sm:text-sm shadow-xs transition-all"
                    >
                        <ShieldCheckIcon class="w-4 h-4 text-indigo-600" />
                        <span>الأدوار والصلاحيات</span>
                    </Link>
                    <button 
                        @click="openAddModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/20 transition-all duration-150"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>إضافة مستخدم</span>
                    </button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">إجمالي المستخدمين المسجلين</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-900">{{ stats?.total_users ?? 0 }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
                    <p class="text-xs text-slate-400 font-medium mb-1">المستخدمين النشطين</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ stats?.active_users ?? 0 }}</p>
                </div>
            </div>

            <!-- Users Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/50 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="py-4 px-6 text-start">المستخدم</th>
                                <th class="py-4 px-6 text-start">البريد الإلكتروني</th>
                                <th class="py-4 px-6 text-start">الفرع</th>
                                <th class="py-4 px-6 text-center">الدور / الصلاحية</th>
                                <th class="py-4 px-6 text-center">الحالة</th>
                                <th class="py-4 px-6 text-center">إجراءات</th>
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
                                    <span v-if="userIsOwner(u) || !(u.branches?.length || u.branch)" class="text-slate-400">كل الفروع</span>
                                    <span v-else class="inline-flex items-start gap-1 font-semibold">
                                        <BuildingOfficeIcon class="w-3.5 h-3.5 text-slate-400 mt-0.5 shrink-0" />
                                        <span>{{ (u.branches?.length ? u.branches : [u.branch]).map((b) => b.name_ar).join('، ') }}</span>
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
                                        {{ u.status === 'active' ? 'نشط' : 'معطل' }}
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
                                        <button 
                                            v-if="$can('admin.users.edit')"
                                            @click="openEditModal(u)"
                                            class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition"
                                            title="تعديل"
                                        >
                                            <PencilSquareIcon class="w-4 h-4" />
                                        </button>
                                        <button 
                                            v-if="$can('admin.users.delete')"
                                            @click="deleteUser(u.id, u.name)"
                                            class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                            title="حذف"
                                        >
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!users?.data || users.data.length === 0">
                                <td colspan="6" class="py-12 text-center text-slate-400">
                                    لا يوجد مستخدمين مسجلين حتى الآن.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="users?.total > 0" class="p-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span>الإجمالي {{ users.total || 0 }}.</span>
                        <span>لكل صفحة:</span>
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
                        <span>الصفحة {{ users.current_page }} من {{ users.last_page || 1 }}.</span>
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

        <!-- Add/Edit User Modal -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs">
            <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl border border-slate-100 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="font-black text-slate-900 text-sm">
                        {{ editingUser ? 'تعديل بيانات المستخدم' : 'إضافة مستخدم جديد' }}
                    </h3>
                    <button @click="isModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitUser" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الاسم الكامل *</label>
                        <input 
                            v-model="form.name" 
                            type="text" 
                            required
                            placeholder="مثال: أحمد محمد السعيد"
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        />
                        <p v-if="form.errors.name" class="text-[11px] text-rose-600 mt-1">{{ form.errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">البريد الإلكتروني *</label>
                            <input 
                                v-model="form.email" 
                                type="email" 
                                required
                                placeholder="name@company.com"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                dir="ltr"
                            />
                            <p v-if="form.errors.email" class="text-[11px] text-rose-600 mt-1">{{ form.errors.email }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">رقم الهاتف</label>
                            <input 
                                v-model="form.phone" 
                                type="text" 
                                placeholder="0500000000"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                dir="ltr"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">
                            {{ editingUser ? 'كلمة المرور (اتركها فارغة إذا لم ترغب بالتغيير)' : 'كلمة المرور *' }}
                        </label>
                        <input 
                            v-model="form.password" 
                            type="password" 
                            :required="!editingUser"
                            placeholder="********"
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                            dir="ltr"
                        />
                        <p v-if="form.errors.password" class="text-[11px] text-rose-600 mt-1">{{ form.errors.password }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الدور الوظيفي *</label>
                            <select 
                                v-model="form.role"
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                            >
                                <option v-for="r in roles" :key="r.id" :value="r.name">{{ r.label }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Branches the user works in -->
                    <div class="rounded-xl border border-slate-200 p-3 space-y-3">
                        <p v-if="isOwnerRole" class="text-xs text-slate-600">مدير المنشأة يعمل على <b>كل الفروع</b> ويرى مستنداتها جميعاً.</p>
                        <template v-else>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">الفروع المسموح بها *</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label v-for="b in branches" :key="b.id" class="flex items-center gap-2 text-xs rounded-lg border border-slate-100 px-2 py-1.5 cursor-pointer hover:bg-slate-50">
                                        <input type="checkbox" :value="b.id" v-model="form.branch_ids" class="rounded border-slate-300 text-indigo-600" data-branch-option />
                                        <span>{{ b.name_ar }}</span>
                                    </label>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-1">ينشئ المستندات ويرى مستندات وتقارير هذه الفروع فقط. لرؤية كل الفروع امنحه صلاحية «الوصول لكل الفروع».</p>
                                <p v-if="form.errors.branch_ids" class="text-[11px] text-rose-600 mt-1">{{ form.errors.branch_ids }}</p>
                            </div>
                            <div v-if="allowedBranches.length > 1">
                                <label class="block text-xs font-bold text-slate-700 mb-1">الفرع الافتراضي للمستندات الجديدة</label>
                                <select v-model="form.branch_id" class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600">
                                    <option v-for="b in allowedBranches" :key="b.id" :value="b.id">{{ b.name_ar }}</option>
                                </select>
                            </div>
                            <p v-if="form.errors.branch_id" class="text-[11px] text-rose-600">{{ form.errors.branch_id }}</p>
                        </template>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">حالة الحساب *</label>
                        <select 
                            v-model="form.status"
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-bold"
                        >
                            <option value="active">نشط (يمكنه تسجيل الدخول)</option>
                            <option value="inactive">معطل (موقوف مؤقتاً)</option>
                        </select>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                        <button 
                            type="button" 
                            @click="isModalOpen = false"
                            class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition disabled:opacity-50"
                        >
                            {{ editingUser ? 'حفظ التعديلات' : 'إنشاء المستخدم' }}
                        </button>
                    </div>
                </form>
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

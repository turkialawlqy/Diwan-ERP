<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ user: { type: Object, default: null }, branches: Array, roles: Array });

const editingUser = props.user?.id ? props.user : null;
const u = props.user;

const form = useForm({
    name: u?.name || '',
    email: u?.email || '',
    password: '',
    phone: u?.phone || '',
    branch_id: u?.branch_id || props.branches?.[0]?.id || '',
    branch_ids: u ? (u.branches?.length ? u.branches.map((b) => b.id) : (u.branch_id ? [u.branch_id] : [])) : (props.branches?.[0]?.id ? [props.branches[0].id] : []),
    role: u?.roles?.[0]?.name || props.roles?.[0]?.name || 'sales',
    status: u?.status || 'active',
});
useUnsavedGuard(form);

// The owner role works in every branch; other users work in the ticked ones.
const isOwnerRole = computed(() => !!props.roles?.find((r) => r.name === form.role)?.is_owner);
const allowedBranches = computed(() => (props.branches || []).filter((b) => form.branch_ids.includes(b.id)));
watch(() => [...form.branch_ids], (ids) => {
    if (!ids.includes(form.branch_id)) form.branch_id = ids[0] || '';
});

const submitUser = () => (editingUser
    ? form.put(route('users.update', editingUser.id))
    : form.post(route('users.store')));
</script>

<template>
    <AppLayout>
        <Head :title="props.user?.id ? 'تعديل مستخدم' : 'مستخدم جديد'" />
        <PageHeader :title="props.user?.id ? 'تعديل مستخدم' : 'مستخدم جديد'" :crumbs="[{ label: 'الإدارة' }, { label: 'المستخدمون', href: route('users.index') }, { label: props.user?.id ? 'تعديل' : 'جديد' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitUser" class="form-grid text-xs">
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
                                        <p class="text-[11px] text-slate-500 mt-1">ينشئ المستندات ويرى مستندات وتقارير هذه الفروع فقط. لرؤية كل الفروع امنحه صلاحية «الوصول لكل الفروع». ولا يرى ولا يفتح إلا الأنشطة المفعّلة في هذه الفروع (تُضبط من «الفروع»)، ولا بيانات فرع غير مختار.</p>
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
                                <Link :href="route('users.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
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
    </AppLayout>
</template>

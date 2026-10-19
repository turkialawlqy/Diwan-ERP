<script setup>
import { ref } from 'vue';
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

const eventLabels = {
    created: 'إنشاء',
    updated: 'تعديل',
    deleted: 'حذف',
    login: 'تسجيل دخول',
    login_failed: 'محاولة دخول فاشلة',
    role_created: 'إنشاء دور',
    role_renamed: 'إعادة تسمية دور',
    role_deleted: 'حذف دور',
    role_permissions_updated: 'تعديل صلاحيات دور',
    user_created: 'إنشاء مستخدم',
    user_updated: 'تعديل مستخدم',
    user_deleted: 'حذف مستخدم',
    user_permissions_updated: 'تعديل صلاحيات مستخدم',
};

const eventClass = (event) => {
    if (event.includes('deleted') || event === 'login_failed') return 'bg-rose-50 text-rose-700 border-rose-200';
    if (event.includes('created') || event === 'login') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    return 'bg-amber-50 text-amber-700 border-amber-200';
};

const format = (value) => (typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '—'));
</script>

<template>
    <AppLayout>
        <Head title="سجل التدقيق" />

        <div class="space-y-5">
            <div class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                <Link :href="route('dashboard')" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center">
                    <HomeIcon class="w-4 h-4" />
                </Link>
                <ChevronRightIcon class="w-3.5 h-3.5 text-slate-300 mx-2 rtl:rotate-180" />
                <span class="text-indigo-600 font-bold">{{ $t('nav.audit_log') }}</span>
            </div>

            <!-- Filters -->
            <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 text-xs">
                <select v-model="filters.user_id" class="rounded-xl border-slate-200 text-xs">
                    <option value="">كل المستخدمين</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.event" class="rounded-xl border-slate-200 text-xs">
                    <option value="">كل العمليات</option>
                    <option v-for="(label, key) in eventLabels" :key="key" :value="key">{{ label }}</option>
                </select>
                <input v-model="filters.type" placeholder="نوع السجل (مثل Invoice)" class="rounded-xl border-slate-200 text-xs" />
                <input v-model="filters.from" type="date" class="rounded-xl border-slate-200 text-xs" />
                <input v-model="filters.to" type="date" class="rounded-xl border-slate-200 text-xs" />
                <button type="submit" class="rounded-xl bg-indigo-600 text-white font-bold px-4 py-2 hover:bg-indigo-700">{{ $t('common.filter') }}</button>
            </form>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-start px-4 py-3">التاريخ</th>
                                <th class="text-start px-4 py-3">المستخدم</th>
                                <th class="text-start px-4 py-3">العملية</th>
                                <th class="text-start px-4 py-3">السجل</th>
                                <th class="text-start px-4 py-3">IP</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="log in logs.data" :key="log.id">
                                <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td class="px-4 py-3 font-mono text-slate-600" dir="ltr">{{ log.created_at }}</td>
                                    <td class="px-4 py-3 text-slate-700 font-semibold">{{ log.user ?? 'النظام' }}</td>
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
                                                <tr><th class="text-start py-1">الحقل</th><th class="text-start py-1">قبل</th><th class="text-start py-1">بعد</th></tr>
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
                    <span>الصفحة {{ logs.current_page }} من {{ logs.last_page }}</span>
                    <div class="flex gap-2">
                        <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" preserve-scroll class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">السابق</Link>
                        <Link v-if="logs.next_page_url" :href="logs.next_page_url" preserve-scroll class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">التالي</Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

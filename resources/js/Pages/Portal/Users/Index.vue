<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';

const props = defineProps({ users: Object, filters: Object, customer: Object, portalUrl: String });
const search = ref(props.filters.search || '');
const go = () => router.get(route('portal.users.index'), { customer: props.filters.customer || undefined, search: search.value || undefined }, { preserveState: true, replace: true });
const toggle = (u) => router.put(route('portal.users.update', u.id), { status: u.status === 'active' ? 'disabled' : 'active' }, { preserveScroll: true });
const resend = (u) => router.post(route('portal.users.resend', u.id), {}, { preserveScroll: true });
const remove = (u) => { if (confirm(`حذف حساب ${u.name}؟`)) router.delete(route('portal.users.destroy', u.id), { preserveScroll: true }); };
const copied = ref(false);
const copy = async () => { try { await navigator.clipboard.writeText(props.portalUrl); copied.value = true; setTimeout(() => (copied.value = false), 2000); } catch { /* ignore */ } };
</script>

<template>
    <AppLayout>
        <Head title="مستخدمو بوابة العملاء" />
        <PageHeader title="مستخدمو بوابة العملاء" :subtitle="customer ? `حسابات ${customer.name}` : 'من يدخل بوابة العملاء برمز يصل بريده'" :crumbs="[{ label: 'المبيعات' }, { label: 'بوابة العملاء' }]">
            <template #actions>
                <button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="copy" data-copy-portal :data-portal-url="portalUrl">{{ copied ? 'تم النسخ' : 'نسخ رابط البوابة' }}</button>
                <Link :href="route('portal.users.create', { customer: filters.customer || undefined })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-portal-user>دعوة مستخدم</Link>
            </template>
        </PageHeader>
        <div class="mb-3"><input v-model="search" @keyup.enter="go" placeholder="بحث بالاسم أو البريد أو العميل" class="w-full md:w-80 rounded-xl border-slate-200 text-xs" /></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-3 text-start">المستخدم</th><th class="px-4 py-3 text-start">العميل</th><th class="px-4 py-3 text-start">الحالة</th><th class="px-4 py-3 text-start">آخر دخول</th><th class="px-4 py-3"></th></tr></thead>
                <tbody>
                    <tr v-for="u in users.data" :key="u.id" class="border-t border-slate-100" :data-portal-user="u.email">
                        <td class="px-4 py-3"><p class="font-bold">{{ u.name }}</p><p class="text-slate-500" dir="ltr">{{ u.email }}</p></td>
                        <td class="px-4 py-3">{{ u.customer?.name }}</td>
                        <td class="px-4 py-3"><span :class="u.status === 'active' ? 'text-emerald-600' : 'text-rose-600'" class="font-bold">{{ u.status === 'active' ? 'نشط' : 'معطّل' }}</span></td>
                        <td class="px-4 py-3 font-mono">{{ u.last_login_at?.slice(0, 16).replace('T', ' ') || '—' }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <button v-if="u.status === 'active'" type="button" class="px-2 py-1 text-indigo-600 font-bold" @click="resend(u)">إعادة الدعوة</button>
                            <button type="button" class="px-2 py-1 font-bold" :class="u.status === 'active' ? 'text-amber-600' : 'text-emerald-600'" @click="toggle(u)">{{ u.status === 'active' ? 'تعطيل' : 'تفعيل' }}</button>
                            <button type="button" class="px-2 py-1 text-rose-600 font-bold" @click="remove(u)">حذف</button>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length"><td colspan="5" class="py-8 text-center text-slate-400">لا مستخدمين بعد.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="users" />
    </AppLayout>
</template>

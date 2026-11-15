<script setup>
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
const { t } = useI18n();

const props = defineProps({ users: Object, filters: Object, customer: Object, portalUrl: String });
const search = ref(props.filters.search || '');
const go = () => router.get(route('portal.users.index'), { customer: props.filters.customer || undefined, search: search.value || undefined }, { preserveState: true, replace: true });
const toggle = (u) => router.put(route('portal.users.update', u.id), { status: u.status === 'active' ? 'disabled' : 'active' }, { preserveScroll: true });
const resend = (u) => router.post(route('portal.users.resend', u.id), {}, { preserveScroll: true });
const remove = (u) => { if (confirm(t('por_portal_users_index.delete_the_account_n', { n0: u.name }))) router.delete(route('portal.users.destroy', u.id), { preserveScroll: true }); };
const copied = ref(false);
const copy = async () => { try { await navigator.clipboard.writeText(props.portalUrl); copied.value = true; setTimeout(() => (copied.value = false), 2000); } catch { /* ignore */ } };
</script>

<template>
    <AppLayout>
        <Head :title="$t('por_portal_users_index.customer_portal_users')" />
        <PageHeader :title="$t('por_portal_users_index.customer_portal_users')" :subtitle="customer ? $t('por_portal_users_index.accounts_of_n', { n0: customer.name }) : $t('por_portal_users_index.who_signs_in_to_the_customer_por')" :crumbs="[{ label: $t('por_portal_users_index.sales') }, { label: $t('por_portal_users_index.customer_portal') }]">
            <template #actions>
                <button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="copy" data-copy-portal :data-portal-url="portalUrl">{{ copied ? $t('por_portal_users_index.copied') : $t('por_portal_users_index.copy_the_portal_link') }}</button>
                <Link :href="route('portal.users.create', { customer: filters.customer || undefined })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-portal-user>{{ $t('por_portal_users_index.invite_a_user') }}</Link>
            </template>
        </PageHeader>
        <div class="mb-3"><input v-model="search" @keyup.enter="go" :placeholder="$t('por_portal_users_index.search_by_name_email_or_customer')" class="w-full md:w-80 rounded-xl border-slate-200 text-xs" /></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-3 text-start">{{ $t('por_portal_users_index.user') }}</th><th class="px-4 py-3 text-start">{{ $t('por_portal_users_index.customer') }}</th><th class="px-4 py-3 text-start">{{ $t('por_portal_users_index.status') }}</th><th class="px-4 py-3 text-start">{{ $t('por_portal_users_index.last_sign_in') }}</th><th class="px-4 py-3"></th></tr></thead>
                <tbody>
                    <tr v-for="u in users.data" :key="u.id" class="border-t border-slate-100" :data-portal-user="u.email">
                        <td class="px-4 py-3"><p class="font-bold">{{ u.name }}</p><p class="text-slate-500" dir="ltr">{{ u.email }}</p></td>
                        <td class="px-4 py-3">{{ u.customer?.name }}</td>
                        <td class="px-4 py-3"><span :class="u.status === 'active' ? 'text-emerald-600' : 'text-rose-600'" class="font-bold">{{ u.status === 'active' ? $t('por_portal_users_index.active') : $t('por_portal_users_index.disabled') }}</span></td>
                        <td class="px-4 py-3 font-mono">{{ u.last_login_at?.slice(0, 16).replace('T', ' ') || '—' }}</td>
                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <button v-if="u.status === 'active'" type="button" class="px-2 py-1 text-indigo-600 font-bold" @click="resend(u)">{{ $t('por_portal_users_index.resend_the_invitation') }}</button>
                            <button type="button" class="px-2 py-1 font-bold" :class="u.status === 'active' ? 'text-amber-600' : 'text-emerald-600'" @click="toggle(u)">{{ u.status === 'active' ? $t('por_portal_users_index.disable') : $t('por_portal_users_index.activate') }}</button>
                            <button type="button" class="px-2 py-1 text-rose-600 font-bold" @click="remove(u)">{{ $t('por_portal_users_index.delete') }}</button>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length"><td colspan="5" class="py-8 text-center text-slate-400">{{ $t('por_portal_users_index.no_users_yet') }}</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="users" />
    </AppLayout>
</template>

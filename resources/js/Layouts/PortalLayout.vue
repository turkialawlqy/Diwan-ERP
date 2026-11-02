<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

/**
 * The customer portal's frame: company name, the sections the company has on, sign-out, and the
 * flash message. It is deliberately separate from the staff AppLayout (no sidebar, no staff menus).
 */
const props = defineProps({ portal: { type: Object, required: true }, active: { type: String, default: '' } });
const page = usePage();
const id = computed(() => props.portal.company.id);
const r = (name, params = {}) => route(name, { company: id.value, ...params });
const items = computed(() => [
    { key: 'home', label: 'الرئيسية', href: r('customer-portal.home') },
    { key: 'invoices', label: 'الفواتير', href: r('customer-portal.invoices.index') },
    { key: 'statement', label: 'كشف الحساب', href: r('customer-portal.statement') },
    { key: 'payments', label: 'الدفعات', href: r('customer-portal.payments') },
    ...(props.portal.menu.support ? [{ key: 'tickets', label: 'التذاكر', href: r('customer-portal.tickets.index') }] : []),
    ...(props.portal.menu.bookings ? [{ key: 'bookings', label: 'حجوزاتي', href: r('customer-portal.bookings.index') }] : []),
    ...(props.portal.menu.contracts ? [{ key: 'contracts', label: 'العقود', href: r('customer-portal.contracts.index') }] : []),
    { key: 'subscriptions', label: 'الاشتراكات', href: r('customer-portal.subscriptions.index') },
    ...(props.portal.menu.projects ? [{ key: 'projects', label: 'المشاريع', href: r('customer-portal.projects.index') }] : []),
]);
const logout = () => router.post(r('customer-portal.logout'));
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-800" dir="rtl">
        <header class="bg-white border-b border-slate-200">
            <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
                <div><p class="font-black text-slate-900">{{ portal.company.name }}</p><p class="text-3xs text-slate-500">بوابة العملاء</p></div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-slate-500 hidden sm:inline">{{ portal.user.name }}</span>
                    <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold text-slate-600 hover:bg-slate-50" @click="logout" data-portal-logout>خروج</button>
                </div>
            </div>
            <nav class="max-w-6xl mx-auto px-4 flex gap-1 overflow-x-auto text-xs font-bold" data-portal-nav>
                <Link v-for="i in items" :key="i.key" :href="i.href" class="px-3 py-2 whitespace-nowrap border-b-2" :class="active === i.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-800'">{{ i.label }}</Link>
            </nav>
        </header>
        <main class="max-w-6xl mx-auto px-4 py-5">
            <div v-if="page.props.flash?.success" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs font-bold px-4 py-3" data-portal-flash>{{ page.props.flash.success }}</div>
            <slot />
        </main>
    </div>
</template>

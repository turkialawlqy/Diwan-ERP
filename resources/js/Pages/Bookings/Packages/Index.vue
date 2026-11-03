<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

const props = defineProps({ templates: Array, sold: Object, filters: Object, statuses: Object });
const f = reactive({ ...props.filters });
const go = () => router.get(route('bookings.packages.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const tone = { active: 'text-emerald-700', used_up: 'text-slate-500', expired: 'text-rose-600' };
</script>

<template>
    <AppLayout>
        <Head title="باقات الزيارات" />
        <PageHeader title="باقات الزيارات" subtitle="زيارات مدفوعة مسبقًا تُخصم منها الحجوزات" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الباقات' }]">
            <template #actions>
                <Link v-if="$can('bookings.packages.create')" :href="route('bookings.customer-packages.sell')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" data-sell-package>بيع باقة لعميل</Link>
                <Link v-if="$can('bookings.packages.create')" :href="route('bookings.packages.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-package><PlusIcon class="w-4 h-4" /> باقة جديدة</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-5">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">قوالب الباقات</h2>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الباقة</th><th class="px-4 py-2 text-start">الخدمة</th><th class="px-4 py-2 text-start">الزيارات</th><th class="px-4 py-2 text-start">السعر</th><th class="px-4 py-2 text-start">الصلاحية</th><th class="px-4 py-2 w-16"></th></tr></thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" class="border-t border-slate-100" :data-template="t.name">
                        <td class="px-4 py-2 font-bold">{{ t.name }}<span v-if="!t.is_active" class="text-slate-400 font-normal"> (معطّلة)</span></td><td class="px-4 py-2">{{ t.service?.name || 'أي خدمة' }}</td><td class="px-4 py-2 font-mono">{{ t.visits }}</td>
                        <td class="px-4 py-2 font-mono">{{ money(t.price) }}</td><td class="px-4 py-2">{{ t.valid_days ? `${t.valid_days} يوم` : 'بلا حد' }}</td>
                        <td class="px-4 py-2 text-end"><RowActions :edit="$can('bookings.packages.edit') ? route('bookings.packages.edit', t.id) : null" :remove="$can('bookings.packages.delete') ? route('bookings.packages.destroy', t.id) : null" :confirm="`حذف الباقة ${t.name}؟`" /></td>
                    </tr>
                    <tr v-if="!templates.length"><td colspan="6" class="py-8 text-center text-slate-400">لا قوالب بعد.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-3 flex gap-2 text-xs">
            <input v-model="f.search" @keyup.enter="go" placeholder="رقم الباقة أو العميل" class="rounded-xl border-slate-200 text-xs w-48" />
            <select v-model="f.status" @change="go" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">الباقات المباعة</h2>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الرقم</th><th class="px-4 py-2 text-start">العميل</th><th class="px-4 py-2 text-start">الباقة</th><th class="px-4 py-2 text-start">المتبقي</th><th class="px-4 py-2 text-start">تنتهي</th><th class="px-4 py-2 text-start">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="p in sold.data" :key="p.id" class="border-t border-slate-100" :data-package="p.number">
                        <td class="px-4 py-2"><Link :href="route('bookings.customer-packages.show', p.id)" class="font-mono font-bold text-indigo-600">{{ p.number }}</Link></td><td class="px-4 py-2 font-bold">{{ p.customer?.name }}</td><td class="px-4 py-2">{{ p.name }}</td>
                        <td class="px-4 py-2 font-mono">{{ p.visits_total - p.visits_used }} / {{ p.visits_total }}</td><td class="px-4 py-2 font-mono">{{ p.expires_at ? String(p.expires_at).slice(0, 10) : '—' }}</td><td class="px-4 py-2 font-bold" :class="tone[p.status]">{{ statuses[p.status] }}</td>
                    </tr>
                    <tr v-if="!sold.data.length"><td colspan="6" class="py-8 text-center text-slate-400">لا باقات مباعة.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="sold" />
    </AppLayout>
</template>

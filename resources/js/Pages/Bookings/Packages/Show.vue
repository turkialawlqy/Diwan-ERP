<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { money } from '@/utils/format';

const props = defineProps({ package: Object, remaining: Number, statuses: Object });
const giveBack = () => { if (confirm('إرجاع زيارة واحدة إلى الباقة؟')) router.post(route('bookings.customer-packages.give-back', props.package.id), {}, { preserveScroll: true }); };
</script>

<template>
    <AppLayout>
        <Head :title="`باقة ${package.number}`" />
        <PageHeader :title="`${package.number} — ${package.name}`" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الباقات', href: route('bookings.packages.index') }, { label: package.number }]">
            <template #actions>
                <button v-if="package.visits_used > 0 && $can('bookings.packages.edit')" type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="giveBack" data-give-back>إرجاع زيارة</button>
            </template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">العميل</p><p class="font-bold">{{ package.customer?.name }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">المتبقي</p><p class="text-lg font-black font-mono" data-remaining>{{ remaining }} / {{ package.visits_total }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">الحالة</p><p class="font-bold">{{ statuses[package.status] }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">تنتهي</p><p class="font-mono font-bold">{{ package.expires_at ? String(package.expires_at).slice(0, 10) : '—' }}</p></div>
        </div>
        <p v-if="package.invoice" class="text-xs mb-3">الفاتورة: <Link :href="route('sales.invoices.show', package.invoice.id)" class="font-mono font-bold text-indigo-600">{{ package.invoice.invoice_number }}</Link> — {{ money(package.invoice.total) }}</p>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <h2 class="px-4 py-3 text-xs font-bold border-b border-slate-100">سجل الاستعمال</h2>
            <table class="w-full text-xs"><tbody>
                <tr v-for="u in package.usages" :key="u.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ String(u.created_at).slice(0, 16).replace('T', ' ') }}</td><td class="px-4 py-2 font-mono font-bold" :class="u.delta < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ u.delta > 0 ? '+' : '' }}{{ u.delta }}</td><td class="px-4 py-2">{{ u.note }}</td></tr>
                <tr v-if="!package.usages.length"><td class="py-6 text-center text-slate-400">لم تُستعمل بعد.</td></tr>
            </tbody></table>
        </div>
    </AppLayout>
</template>

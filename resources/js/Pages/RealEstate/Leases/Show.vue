<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import { money } from '@/utils/format';

const props = defineProps({ lease: Object, invoices: Array, requests: Array, statuses: Object, cycles: Object });
const ending = ref(false);
const end = useForm({ reason: '' });
const doEnd = () => end.post(route('real-estate.leases.end', props.lease.id), { preserveScroll: true, onSuccess: () => (ending.value = false) });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
</script>

<template>
    <AppLayout>
        <Head :title="`عقد ${lease.number}`" />
        <PageHeader :title="`${lease.number} — ${lease.unit_label}`" :crumbs="[{ label: 'العقارات', href: route('real-estate.properties.index') }, { label: 'عقود الإيجار', href: route('real-estate.leases.index') }, { label: lease.number }]">
            <template #actions>
                <ShareDocument v-if="$can('real_estate.leases.view')" type="lease" :id="lease.id" />
                <Link v-if="$can('work_orders.orders.create')" :href="route('work-orders.create', { customer: lease.customer_id, unit: lease.property_unit_id })" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">طلب صيانة</Link>
                <button v-if="lease.status === 'active' && $can('real_estate.leases.edit')" type="button" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold" @click="ending = true" data-end-lease>إنهاء العقد</button>
            </template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">الحالة</p><p class="font-bold" data-status>{{ statuses[lease.status] }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">المستأجر</p><Link :href="route('sales.clients.show', lease.customer_id)" class="font-bold text-indigo-600">{{ lease.customer?.name }}</Link></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">المدة</p><p class="font-mono font-bold">{{ d(lease.start_date) }} → {{ d(lease.end_date) }}</p></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4"><p class="text-slate-500">الإيجار</p><p class="font-mono font-bold">{{ money(lease.rent_amount) }} / {{ cycles[lease.cycle] }}</p></div>
        </div>
        <p class="text-xs mb-3 space-x-3 space-x-reverse"><span v-if="lease.contract">العقد: <Link :href="route('contracts.show', lease.contract.id)" class="font-mono font-bold text-indigo-600">{{ lease.contract.number }}</Link></span>
            <span v-if="lease.subscription">الاشتراك: <Link :href="route('sales.subscriptions.show', lease.subscription.id)" class="font-mono font-bold text-indigo-600">{{ lease.subscription.number }}</Link></span><span v-if="Number(lease.security_deposit)">التأمين: <b class="font-mono">{{ money(lease.security_deposit) }}</b></span></p>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><h2 class="px-5 py-3 font-bold border-b border-slate-100">فواتير الإيجار</h2><table class="w-full"><tbody>
                <tr v-for="i in invoices" :key="i.id" class="border-t border-slate-100" data-rent-invoice><td class="px-4 py-2"><Link :href="route('sales.invoices.show', i.id)" class="font-mono font-bold text-indigo-600">{{ i.invoice_number }}</Link></td><td class="px-4 py-2 font-mono">{{ d(i.invoice_date) }}</td><td class="px-4 py-2 font-mono">{{ money(i.total) }}</td>
                    <td class="px-4 py-2 font-mono" :class="Number(i.remaining_amount) > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'">{{ Number(i.remaining_amount) > 0 ? `متبقٍ ${money(i.remaining_amount)}` : 'مسددة' }}</td></tr>
                <tr v-if="!invoices.length"><td class="py-6 text-center text-slate-400">لا فواتير بعد.</td></tr></tbody></table></div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><h2 class="px-5 py-3 font-bold border-b border-slate-100">طلبات الصيانة</h2><table class="w-full"><tbody>
                <tr v-for="r in requests" :key="r.id" class="border-t border-slate-100"><td class="px-4 py-2"><Link :href="route('work-orders.show', r.id)" class="font-mono font-bold text-indigo-600">{{ r.number }}</Link></td><td class="px-4 py-2">{{ r.title }}</td><td class="px-4 py-2">{{ r.status }}</td></tr>
                <tr v-if="!requests.length"><td class="py-6 text-center text-slate-400">لا طلبات.</td></tr></tbody></table></div>
        </div>
        <Dialog :show="ending" title="إنهاء عقد الإيجار" @close="ending = false">
            <input v-model="end.reason" placeholder="السبب (اختياري)" class="w-full rounded-xl border-slate-200 text-xs" /><p class="text-3xs text-slate-500 mt-1">يتوقف الإيجار الدوري ويُنهى العقد وتصبح الوحدة شاغرة.</p>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="ending = false">رجوع</button><button type="button" :disabled="end.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" @click="doEnd" data-confirm-end>تأكيد الإنهاء</button></template>
        </Dialog>
    </AppLayout>
</template>

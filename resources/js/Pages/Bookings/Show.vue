<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import { money } from '@/utils/format';

const props = defineProps({ booking: Object, coveringPackages: Array, net: Number, statuses: Object });
const b = computed(() => props.booking);
const open = computed(() => ['pending', 'confirmed'].includes(b.value.status));
const when = (v) => String(v).replace('T', ' ').slice(0, 16);
const post = (name, data = {}) => router.post(route(name, b.value.id), data, { preserveScroll: true });
const tone = { pending: 'bg-amber-50 text-amber-700 border-amber-200', confirmed: 'bg-sky-50 text-sky-700 border-sky-200', completed: 'bg-emerald-50 text-emerald-700 border-emerald-200', cancelled: 'bg-rose-50 text-rose-700 border-rose-200', no_show: 'bg-slate-100 text-slate-600 border-slate-200' };

const cancelling = ref(false);
const cancel = useForm({ reason: '' });
const doCancel = () => cancel.post(route('bookings.cancel', b.value.id), { preserveScroll: true, onSuccess: () => (cancelling.value = false) });

const completing = ref(false);
const done = useForm({ use_package: true, customer_package_id: '' });
const doComplete = () => done.transform((d) => ({ ...d, customer_package_id: d.use_package ? d.customer_package_id || null : null })).post(route('bookings.complete', b.value.id), { preserveScroll: true, onSuccess: () => (completing.value = false) });
</script>

<template>
    <AppLayout>
        <Head :title="`حجز ${b.number}`" />
        <PageHeader :title="`${b.number} — ${b.customer?.name}`" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: b.number }]">
            <template #actions>
                <template v-if="open && $can('bookings.bookings.edit')">
                    <button v-if="b.status === 'pending'" type="button" class="px-4 py-2 rounded-xl bg-sky-600 text-white text-xs font-bold" @click="post('bookings.confirm')" data-confirm>تأكيد</button>
                    <button type="button" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="completing = true" data-complete>إنجاز</button>
                    <Link :href="route('bookings.edit', b.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" data-reschedule>تغيير الموعد</Link>
                    <button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="post('bookings.no-show')" data-no-show>لم يحضر</button>
                    <button type="button" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold" @click="cancelling = true" data-cancel>إلغاء الحجز</button>
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-2 gap-3">
                <div><p class="text-slate-500">الحالة</p><span class="inline-block px-2.5 py-1 rounded-full border font-bold" :class="tone[b.status]" data-status>{{ statuses[b.status] }}</span></div>
                <div><p class="text-slate-500">الموعد</p><p class="font-mono font-bold">{{ when(b.starts_at) }} → {{ String(b.ends_at).slice(11, 16) }}</p></div>
                <div><p class="text-slate-500">الخدمة</p><p class="font-bold">{{ b.service?.name }}</p></div>
                <div><p class="text-slate-500">المورد</p><p class="font-bold">{{ b.resource?.name }}</p></div>
                <div><p class="text-slate-500">العميل</p><Link :href="route('sales.clients.show', b.customer_id)" class="font-bold text-indigo-600">{{ b.customer?.name }}</Link><p class="text-slate-500" dir="ltr">{{ b.customer?.phone }}</p></div>
                <div><p class="text-slate-500">المصدر</p><p class="font-bold">{{ b.source === 'portal' ? 'بوابة العملاء' : 'الموظف' }}</p></div>
                <div v-if="b.notes" class="col-span-2"><p class="text-slate-500">ملاحظات</p><p class="whitespace-pre-line">{{ b.notes }}</p></div>
                <div v-if="b.cancel_reason" class="col-span-2"><p class="text-slate-500">سبب الإلغاء</p><p>{{ b.cancel_reason }}</p></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                <h2 class="font-bold text-slate-800">المبلغ</h2>
                <p class="flex justify-between"><span>السعر</span><span class="font-mono">{{ money(b.price) }}</span></p>
                <p v-if="Number(b.member_discount)" class="flex justify-between text-emerald-700"><span>خصم العضوية</span><span class="font-mono">- {{ money(b.member_discount) }}</span></p>
                <p class="flex justify-between font-bold border-t border-slate-100 pt-2"><span>الصافي قبل الضريبة</span><span class="font-mono" data-net>{{ money(net) }}</span></p>
                <p v-if="Number(b.deposit_amount)" class="flex justify-between"><span>العربون المستلم</span><span class="font-mono">{{ money(b.deposit_amount) }}</span></p>
                <p v-if="b.invoice" class="pt-2 border-t border-slate-100">الفاتورة: <Link :href="route('sales.invoices.show', b.invoice.id)" class="font-mono font-bold text-indigo-600" data-invoice>{{ b.invoice.invoice_number }}</Link></p>
                <p v-if="b.package" class="pt-2 border-t border-slate-100">خُصمت زيارة من الباقة: <Link :href="route('bookings.customer-packages.show', b.package.id)" class="font-mono font-bold text-indigo-600" data-used-package>{{ b.package.number }}</Link></p>
            </div>
        </div>

        <Dialog :show="completing" title="إنجاز الحجز" @close="completing = false">
            <div class="space-y-3 text-xs">
                <label v-if="coveringPackages.length" class="flex items-center gap-2"><input v-model="done.use_package" type="checkbox" class="rounded border-slate-300" data-use-package /> خصم زيارة من باقة العميل (بلا فاتورة)</label>
                <select v-if="coveringPackages.length && done.use_package" v-model="done.customer_package_id" class="w-full rounded-xl border-slate-200 text-xs">
                    <option value="">الأقرب انتهاءً تلقائيًا</option><option v-for="p in coveringPackages" :key="p.id" :value="p.id">{{ p.number }} — {{ p.name }} (متبقٍ {{ p.remaining }})</option>
                </select>
                <p v-if="!coveringPackages.length || !done.use_package" class="text-slate-600">ستُصدر فاتورة للعميل بالصافي {{ money(net) }}<span v-if="Number(b.deposit_amount)"> بعد خصم العربون {{ money(b.deposit_amount) }}</span>.</p>
                <p v-if="done.errors.customer_package_id" class="text-rose-600 font-bold">{{ done.errors.customer_package_id }}</p>
            </div>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="completing = false">رجوع</button><button type="button" :disabled="done.processing" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="doComplete" data-confirm-complete>تأكيد الإنجاز</button></template>
        </Dialog>
        <Dialog :show="cancelling" title="إلغاء الحجز" @close="cancelling = false">
            <input v-model="cancel.reason" placeholder="سبب الإلغاء (اختياري)" class="w-full rounded-xl border-slate-200 text-xs" data-cancel-reason />
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="cancelling = false">رجوع</button><button type="button" :disabled="cancel.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" @click="doCancel" data-confirm-cancel>تأكيد الإلغاء</button></template>
        </Dialog>
    </AppLayout>
</template>

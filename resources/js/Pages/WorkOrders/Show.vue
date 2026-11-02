<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import SignaturePad from '@/Components/WorkOrders/SignaturePad.vue';
import { money } from '@/utils/format';

const props = defineProps({ order: Object, attachments: Array, totals: Object, preview: Object, technicians: Array, products: Array, statuses: Object, priorities: Object });
const o = computed(() => props.order);
const open = computed(() => ['new', 'scheduled', 'in_progress', 'on_hold'].includes(o.value.status));
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
const hm = (m) => `${Math.floor(m / 60)}س ${m % 60}د`;
const act = (name, data = {}) => router.post(route(name, o.value.id), data, { preserveScroll: true });
const tone = { new: 'bg-slate-100 text-slate-600 border-slate-200', scheduled: 'bg-sky-50 text-sky-700 border-sky-200', in_progress: 'bg-emerald-50 text-emerald-700 border-emerald-200', on_hold: 'bg-amber-50 text-amber-700 border-amber-200', done: 'bg-emerald-50 text-emerald-800 border-emerald-200', invoiced: 'bg-indigo-50 text-indigo-700 border-indigo-200', cancelled: 'bg-rose-50 text-rose-700 border-rose-200' };

const cancelling = ref(false);
const cancel = useForm({ reason: '' });
const doCancel = () => cancel.post(route('work-orders.cancel', o.value.id), { preserveScroll: true, onSuccess: () => (cancelling.value = false) });
const completing = ref(false);
const done = useForm({ notes: props.order.completion_notes || '' });
const doComplete = () => done.post(route('work-orders.complete', o.value.id), { preserveScroll: true, onSuccess: () => (completing.value = false) });

const time = useForm({ employee_id: '', minutes: 60, billable: true, note: '' });
const addTime = () => time.post(route('work-orders.time.store', o.value.id), { preserveScroll: true, onSuccess: () => time.reset('minutes', 'note') });
const part = useForm({ product_id: '', quantity: 1, unit_price: '' });
const addPart = () => part.post(route('work-orders.parts.store', o.value.id), { preserveScroll: true, onSuccess: () => part.reset() });
const sign = useForm({ name: '', signature: '' });
const doSign = () => sign.post(route('work-orders.sign', o.value.id), { preserveScroll: true });
const upload = (e) => { const file = e.target.files?.[0]; if (file) router.post(route('work-orders.attachments.store', o.value.id), { file }, { forceFormData: true, preserveScroll: true }); e.target.value = ''; };
const input = 'rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="`أمر عمل ${o.number}`" />
        <PageHeader :title="`${o.number} — ${o.title}`" :crumbs="[{ label: 'أوامر العمل', href: route('work-orders.index') }, { label: o.number }]">
            <template #actions>
                <template v-if="open && $can('work_orders.orders.edit')">
                    <button v-if="o.status === 'in_progress'" type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="act('work-orders.hold')">تعليق</button>
                    <button type="button" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="completing = true" data-complete>إنجاز</button>
                    <Link :href="route('work-orders.edit', o.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" data-edit>تعديل / إسناد</Link>
                    <button type="button" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold" @click="cancelling = true" data-cancel>إلغاء</button>
                </template>
                <button v-if="o.status === 'done' && $can('work_orders.orders.edit')" type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="act('work-orders.reopen')">إعادة فتح</button>
                <button v-if="o.status === 'done' && !o.invoice_id && $can('work_orders.orders.invoice')" type="button" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" @click="act('work-orders.invoice')" data-invoice-btn>إصدار الفاتورة</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-2 gap-3">
                    <div><p class="text-slate-500">الحالة</p><span class="inline-block px-2.5 py-1 rounded-full border font-bold" :class="tone[o.status]" data-status>{{ statuses[o.status] }}</span></div>
                    <div><p class="text-slate-500">العميل</p><Link :href="route('sales.clients.show', o.customer_id)" class="font-bold text-indigo-600">{{ o.customer?.name }}</Link></div>
                    <div><p class="text-slate-500">نوع العمل</p><p class="font-bold">{{ o.work_type?.name || '—' }}</p></div>
                    <div><p class="text-slate-500">الفني</p><p class="font-bold">{{ o.lead?.name || '—' }}<span v-for="c in o.crew" :key="c.id" class="font-normal text-slate-500"> + {{ c.name }}</span></p></div>
                    <div><p class="text-slate-500">الموعد</p><p class="font-mono">{{ when(o.scheduled_start) }} → {{ when(o.scheduled_end) }}</p></div>
                    <div><p class="text-slate-500">الموقع</p><p>{{ o.site_address || '—' }}</p></div>
                    <div v-if="o.ticket" class="col-span-2"><p class="text-slate-500">التذكرة</p><Link :href="route('support.tickets.show', o.ticket.id)" class="font-mono font-bold text-indigo-600">{{ o.ticket.number }}</Link> {{ o.ticket.subject }}</div>
                    <div v-if="o.description" class="col-span-2"><p class="text-slate-500">الوصف</p><p class="whitespace-pre-line">{{ o.description }}</p></div>
                    <div v-if="o.completion_notes" class="col-span-2"><p class="text-slate-500">ملاحظات الإنجاز</p><p class="whitespace-pre-line">{{ o.completion_notes }}</p></div>
                    <div v-if="o.cancel_reason" class="col-span-2"><p class="text-slate-500">سبب الإلغاء</p><p>{{ o.cancel_reason }}</p></div>
                </div>

                <div v-if="o.tasks.length" class="bg-white border border-slate-200 rounded-2xl p-5"><h2 class="font-bold mb-2">المهام</h2>
                    <p v-for="t in o.tasks" :key="t.id" class="py-0.5" :class="t.is_done ? 'text-emerald-700' : ''" :data-task="t.title">{{ t.is_done ? '✓' : '○' }} {{ t.title }}</p></div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-time>
                    <h2 class="px-5 py-3 font-bold border-b border-slate-100">الوقت — {{ hm(totals.minutes) }} (قابل للفوترة {{ hm(totals.billable_minutes) }})</h2>
                    <table class="w-full"><tbody>
                        <tr v-for="e in o.time_entries" :key="e.id" class="border-t border-slate-100"><td class="px-4 py-2 font-bold">{{ e.employee?.name }}</td><td class="px-4 py-2 font-mono">{{ when(e.started_at) }}</td>
                            <td class="px-4 py-2 font-mono">{{ e.ended_at ? hm(e.minutes) : 'يعمل الآن' }}<span v-if="e.auto_closed" class="text-amber-600"> (أُغلق تلقائيًا)</span></td><td class="px-4 py-2">{{ e.billable ? 'قابل للفوترة' : 'غير مفوتَر' }}</td>
                            <td class="px-4 py-2 text-end"><button v-if="!e.ended_at && open" type="button" class="text-rose-600 font-bold" @click="router.post(route('work-orders.time.stop', e.id), {}, { preserveScroll: true })">إيقاف</button></td></tr>
                        <tr v-if="!o.time_entries.length"><td class="py-5 text-center text-slate-400">لا وقت مسجَّل.</td></tr>
                    </tbody></table>
                    <form v-if="open && $can('work_orders.orders.edit')" class="p-3 border-t border-slate-100 flex flex-wrap items-center gap-2" @submit.prevent="addTime">
                        <select v-model="time.employee_id" required :class="input" data-time-employee><option value="">الفني</option><option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                        <input v-model.number="time.minutes" type="text" inputmode="decimal" data-num :class="input" class="w-20 font-mono" data-time-minutes /> دقيقة
                        <label class="flex items-center gap-1"><input v-model="time.billable" type="checkbox" class="rounded border-slate-300" /> قابلة للفوترة</label>
                        <button type="submit" :disabled="time.processing" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold" data-add-time>إضافة ساعات</button>
                    </form>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-parts>
                    <h2 class="px-5 py-3 font-bold border-b border-slate-100">القطع المستهلكة</h2>
                    <table class="w-full"><tbody>
                        <tr v-for="p in o.parts" :key="p.id" class="border-t border-slate-100" :class="p.returned ? 'text-slate-400 line-through' : ''"><td class="px-4 py-2 font-bold">{{ p.product?.name_ar }}</td><td class="px-4 py-2 font-mono">{{ Number(p.quantity) }}</td>
                            <td class="px-4 py-2 font-mono">بسعر {{ money(p.unit_price) }}</td><td class="px-4 py-2 font-mono text-slate-500">تكلفة {{ money(p.unit_cost) }}</td>
                            <td class="px-4 py-2 text-end"><button v-if="!p.returned && open" type="button" class="text-rose-600 font-bold" @click="router.post(route('work-orders.parts.return', p.id), {}, { preserveScroll: true })">إرجاع للمستودع</button></td></tr>
                        <tr v-if="!o.parts.length"><td class="py-5 text-center text-slate-400">لا قطع.</td></tr>
                    </tbody></table>
                    <form v-if="open && $can('work_orders.orders.edit')" class="p-3 border-t border-slate-100 flex flex-wrap items-center gap-2" @submit.prevent="addPart">
                        <div class="w-64"><SelectCreate v-model="part.product_id" :options="products" entity="product" :label="(p) => `${p.name_ar} ${p.sku || ''}`" placeholder="اختر الصنف" required data-part-product /></div><input v-model.number="part.quantity" type="text" inputmode="decimal" data-num :class="input" class="w-20 font-mono" />
                        <input v-model="part.unit_price" placeholder="سعر الوحدة (اختياري)" type="text" inputmode="decimal" data-num :class="input" class="w-36 font-mono" />
                        <button type="submit" :disabled="part.processing" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold" data-add-part>صرف قطعة</button>
                        <p v-if="part.errors.quantity || part.errors.product_id" class="text-rose-600 font-bold">{{ part.errors.quantity || part.errors.product_id }}</p>
                    </form>
                </div>
            </div>

            <div class="space-y-4">
                <div v-if="preview" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-1.5" data-billing>
                    <h2 class="font-bold">{{ o.invoice ? 'الفوترة' : 'معاينة الفوترة' }}</h2>
                    <p v-for="(l, i) in preview.lines" :key="i" class="flex justify-between gap-2"><span class="truncate">{{ l.item_name }} × {{ l.quantity }}</span><span class="font-mono">{{ money(l.quantity * l.unit_price) }}</span></p>
                    <p class="flex justify-between border-t border-slate-100 pt-1.5"><span>الإيراد (قبل الضريبة)</span><b class="font-mono" data-revenue>{{ money(preview.revenue) }}</b></p>
                    <p class="flex justify-between text-slate-500"><span>التكلفة (عمالة + قطع)</span><span class="font-mono" data-cost>{{ money(preview.cost) }}</span></p>
                    <p class="flex justify-between font-bold" :class="preview.margin >= 0 ? 'text-emerald-700' : 'text-rose-600'"><span>الهامش</span><span class="font-mono" data-margin>{{ money(preview.margin) }}</span></p>
                    <p v-if="o.invoice" class="pt-2 border-t border-slate-100">الفاتورة: <Link :href="route('sales.invoices.show', o.invoice.id)" class="font-mono font-bold text-indigo-600" data-invoice>{{ o.invoice.invoice_number }}</Link></p>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                    <h2 class="font-bold">توقيع العميل</h2>
                    <template v-if="o.signature_url"><img :src="o.signature_url" alt="" class="border rounded-lg bg-white" data-signature-img /><p class="text-slate-500">وقّع {{ o.signed_by }} — {{ when(o.signed_at) }}</p></template>
                    <p v-else-if="!open" class="text-slate-400">لم يُوقَّع.</p>
                    <template v-if="open && $can('work_orders.orders.edit')"><input v-model="sign.name" placeholder="اسم الموقّع" :class="input" class="w-full" /><SignaturePad v-model="sign.signature" /><button type="button" :disabled="!sign.signature || !sign.name" class="w-full py-1.5 rounded-lg border border-indigo-200 text-indigo-700 font-bold disabled:opacity-40" @click="doSign">حفظ التوقيع</button></template>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                    <h2 class="font-bold">الصور والمرفقات</h2>
                    <a v-for="a in attachments" :key="a.id" :href="a.url" target="_blank" class="block text-indigo-600 font-bold truncate">{{ a.name }}</a>
                    <label v-if="$can('work_orders.orders.edit')" class="inline-block px-3 py-1.5 rounded-lg border border-slate-200 font-bold text-slate-600 cursor-pointer">رفع ملف<input type="file" accept="image/*,application/pdf" class="sr-only" @change="upload" /></label>
                </div>
            </div>
        </div>

        <Dialog :show="completing" title="إنجاز أمر العمل" @close="completing = false">
            <textarea v-model="done.notes" rows="3" placeholder="ملاحظات الإنجاز" class="w-full rounded-xl border-slate-200 text-xs"></textarea>
            <p v-if="done.errors.timer || done.errors.tasks || done.errors.signature" class="text-rose-600 text-xs font-bold mt-1" data-complete-error>{{ done.errors.timer || done.errors.tasks || done.errors.signature }}</p>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="completing = false">رجوع</button><button type="button" :disabled="done.processing" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="doComplete" data-confirm-complete>تأكيد الإنجاز</button></template>
        </Dialog>
        <Dialog :show="cancelling" title="إلغاء أمر العمل" @close="cancelling = false">
            <input v-model="cancel.reason" placeholder="السبب (اختياري)" class="w-full rounded-xl border-slate-200 text-xs" />
            <p class="text-3xs text-slate-500 mt-1">القطع المصروفة تعود إلى المستودع.</p>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="cancelling = false">رجوع</button><button type="button" :disabled="cancel.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" @click="doCancel" data-confirm-cancel>تأكيد الإلغاء</button></template>
        </Dialog>
    </AppLayout>
</template>

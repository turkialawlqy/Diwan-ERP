<script setup>
import { useI18n } from 'vue-i18n';
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import SignaturePad from '@/Components/WorkOrders/SignaturePad.vue';
import { money } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ order: Object, attachments: Array, totals: Object, preview: Object, technicians: Array, products: Array, statuses: Object, priorities: Object });
const o = computed(() => props.order);
const open = computed(() => ['new', 'scheduled', 'in_progress', 'on_hold'].includes(o.value.status));
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
const hm = (m) => t('wo2_workorders_show.nh_nm', { n0: Math.floor(m / 60), n1: m % 60 });
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
        <Head :title="$t('wo2_workorders_show.work_order_n', { n0: o.number })" />
        <PageHeader :title="`${o.number} — ${o.title}`" :crumbs="[{ label: $t('wo2_workorders_show.work_orders'), href: route('work-orders.index') }, { label: o.number }]">
            <template #actions>
                <ShareDocument v-if="$can('work_orders.orders.view')" type="work_order" :id="o.id" />
                <template v-if="open && $can('work_orders.orders.edit')">
                    <button v-if="o.status === 'in_progress'" type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="act('work-orders.hold')">{{ $t('wo2_workorders_show.hold') }}</button>
                    <button type="button" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="completing = true" data-complete>{{ $t('wo2_workorders_show.complete') }}</button>
                    <Link :href="route('work-orders.edit', o.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" data-edit>{{ $t('wo2_workorders_show.edit_assign') }}</Link>
                    <button type="button" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold" @click="cancelling = true" data-cancel>{{ $t('wo2_workorders_show.cancel') }}</button>
                </template>
                <button v-if="o.status === 'done' && $can('work_orders.orders.edit')" type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="act('work-orders.reopen')">{{ $t('wo2_workorders_show.reopen') }}</button>
                <button v-if="o.status === 'done' && !o.invoice_id && $can('work_orders.orders.invoice')" type="button" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" @click="act('work-orders.invoice')" data-invoice-btn>{{ $t('wo2_workorders_show.issue_the_invoice') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-2 gap-3">
                    <div><p class="text-slate-500">{{ $t('wo2_workorders_show.status') }}</p><span class="inline-block px-2.5 py-1 rounded-full border font-bold" :class="tone[o.status]" data-status>{{ statuses[o.status] }}</span></div>
                    <div><p class="text-slate-500">{{ $t('wo2_workorders_show.customer') }}</p><Link :href="route('sales.clients.show', o.customer_id)" class="font-bold text-indigo-600">{{ o.customer?.name }}</Link></div>
                    <div><p class="text-slate-500">{{ $t('wo2_workorders_show.job_type') }}</p><p class="font-bold">{{ o.work_type?.name || '—' }}</p></div>
                    <div><p class="text-slate-500">{{ $t('wo2_workorders_show.technician') }}</p><p class="font-bold">{{ o.lead?.name || '—' }}<span v-for="c in o.crew" :key="c.id" class="font-normal text-slate-500"> + {{ c.name }}</span></p></div>
                    <div><p class="text-slate-500">{{ $t('wo2_workorders_show.appointment') }}</p><p class="font-mono">{{ when(o.scheduled_start) }} → {{ when(o.scheduled_end) }}</p></div>
                    <div><p class="text-slate-500">{{ $t('wo2_workorders_show.location') }}</p><p>{{ o.site_address || '—' }}</p></div>
                    <div v-if="o.ticket" class="col-span-2"><p class="text-slate-500">{{ $t('wo2_workorders_show.ticket') }}</p><Link :href="route('support.tickets.show', o.ticket.id)" class="font-mono font-bold text-indigo-600">{{ o.ticket.number }}</Link> {{ o.ticket.subject }}</div>
                    <div v-if="o.description" class="col-span-2"><p class="text-slate-500">{{ $t('wo2_workorders_show.description') }}</p><p class="whitespace-pre-line">{{ o.description }}</p></div>
                    <div v-if="o.completion_notes" class="col-span-2"><p class="text-slate-500">{{ $t('wo2_workorders_show.completion_notes') }}</p><p class="whitespace-pre-line">{{ o.completion_notes }}</p></div>
                    <div v-if="o.cancel_reason" class="col-span-2"><p class="text-slate-500">{{ $t('wo2_workorders_show.cancellation_reason') }}</p><p>{{ o.cancel_reason }}</p></div>
                </div>

                <div v-if="o.tasks.length" class="bg-white border border-slate-200 rounded-2xl p-5"><h2 class="font-bold mb-2">{{ $t('wo2_workorders_show.tasks') }}</h2>
                    <p v-for="t in o.tasks" :key="t.id" class="py-0.5" :class="t.is_done ? 'text-emerald-700' : ''" :data-task="t.title">{{ t.is_done ? '✓' : '○' }} {{ t.title }}</p></div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-time>
                    <h2 class="px-5 py-3 font-bold border-b border-slate-100">{{ $t('wo2_workorders_show.time_n_billable_n', { n0: hm(totals.minutes), n1: hm(totals.billable_minutes) }) }}</h2>
                    <table class="w-full"><tbody>
                        <tr v-for="e in o.time_entries" :key="e.id" class="border-t border-slate-100"><td class="px-4 py-2 font-bold">{{ e.employee?.name }}</td><td class="px-4 py-2 font-mono">{{ when(e.started_at) }}</td>
                            <td class="px-4 py-2 font-mono">{{ e.ended_at ? hm(e.minutes) : $t('wo2_workorders_show.running_now') }}<span v-if="e.auto_closed" class="text-amber-600"> {{ $t('wo2_workorders_show.closed_automatically') }}</span></td><td class="px-4 py-2">{{ e.billable ? $t('wo2_workorders_show.billable_2') : $t('wo2_workorders_show.not_billed') }}</td>
                            <td class="px-4 py-2 text-end"><button v-if="!e.ended_at && open" type="button" class="text-rose-600 font-bold" @click="router.post(route('work-orders.time.stop', e.id), {}, { preserveScroll: true })">{{ $t('wo2_workorders_show.stop') }}</button></td></tr>
                        <tr v-if="!o.time_entries.length"><td class="py-5 text-center text-slate-400">{{ $t('wo2_workorders_show.no_time_recorded') }}</td></tr>
                    </tbody></table>
                    <form v-if="open && $can('work_orders.orders.edit')" class="p-3 border-t border-slate-100 flex flex-wrap items-center gap-2" @submit.prevent="addTime">
                        <SelectCreate v-model="time.employee_id" required :options="technicians" entity="employee" :label="(t) => t.name" :placeholder="$t('wo2_workorders_show.technician')" data-time-employee />
                        <input v-model.number="time.minutes" type="text" inputmode="decimal" data-num :class="input" class="w-20 font-mono" data-time-minutes /> {{ $t('wo2_workorders_show.minute') }}
                        <label class="flex items-center gap-1"><input v-model="time.billable" type="checkbox" class="rounded border-slate-300" /> {{ $t('wo2_workorders_show.billable') }}</label>
                        <button type="submit" :disabled="time.processing" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold" data-add-time>{{ $t('wo2_workorders_show.add_hours') }}</button>
                    </form>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-parts>
                    <h2 class="px-5 py-3 font-bold border-b border-slate-100">{{ $t('wo2_workorders_show.parts_used') }}</h2>
                    <table class="w-full"><tbody>
                        <tr v-for="p in o.parts" :key="p.id" class="border-t border-slate-100" :class="p.returned ? 'text-slate-400 line-through' : ''"><td class="px-4 py-2 font-bold">{{ p.product?.name_ar }}</td><td class="px-4 py-2 font-mono">{{ Number(p.quantity) }}</td>
                            <td class="px-4 py-2 font-mono">{{ $t('wo2_workorders_show.at_a_rate_of_n', { n0: money(p.unit_price) }) }}</td><td class="px-4 py-2 font-mono text-slate-500">{{ $t('wo2_workorders_show.cost_n', { n0: money(p.unit_cost) }) }}</td>
                            <td class="px-4 py-2 text-end"><button v-if="!p.returned && open" type="button" class="text-rose-600 font-bold" @click="router.post(route('work-orders.parts.return', p.id), {}, { preserveScroll: true })">{{ $t('wo2_workorders_show.return_to_the_warehouse') }}</button></td></tr>
                        <tr v-if="!o.parts.length"><td class="py-5 text-center text-slate-400">{{ $t('wo2_workorders_show.no_parts') }}</td></tr>
                    </tbody></table>
                    <form v-if="open && $can('work_orders.orders.edit')" class="p-3 border-t border-slate-100 flex flex-wrap items-center gap-2" @submit.prevent="addPart">
                        <div class="w-64"><SelectCreate v-model="part.product_id" :options="products" entity="product" :label="(p) => `${p.name_ar} ${p.sku || ''}`" :placeholder="$t('wo2_workorders_show.choose_the_item')" required data-part-product /></div><input v-model.number="part.quantity" type="text" inputmode="decimal" data-num :class="input" class="w-20 font-mono" />
                        <input v-model="part.unit_price" :placeholder="$t('wo2_workorders_show.unit_price_optional')" type="text" inputmode="decimal" data-num :class="input" class="w-36 font-mono" />
                        <button type="submit" :disabled="part.processing" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold" data-add-part>{{ $t('wo2_workorders_show.issue_a_part') }}</button>
                        <p v-if="part.errors.quantity || part.errors.product_id" class="text-rose-600 font-bold">{{ part.errors.quantity || part.errors.product_id }}</p>
                    </form>
                </div>
            </div>

            <div class="space-y-4">
                <div v-if="preview" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-1.5" data-billing>
                    <h2 class="font-bold">{{ o.invoice ? $t('wo2_workorders_show.billing') : $t('wo2_workorders_show.billing_preview') }}</h2>
                    <p v-for="(l, i) in preview.lines" :key="i" class="flex justify-between gap-2"><span class="truncate">{{ l.item_name }} × {{ l.quantity }}</span><span class="font-mono">{{ money(l.quantity * l.unit_price) }}</span></p>
                    <p class="flex justify-between border-t border-slate-100 pt-1.5"><span>{{ $t('wo2_workorders_show.revenue_before_tax') }}</span><b class="font-mono" data-revenue>{{ money(preview.revenue) }}</b></p>
                    <p class="flex justify-between text-slate-500"><span>{{ $t('wo2_workorders_show.cost_labor_parts') }}</span><span class="font-mono" data-cost>{{ money(preview.cost) }}</span></p>
                    <p class="flex justify-between font-bold" :class="preview.margin >= 0 ? 'text-emerald-700' : 'text-rose-600'"><span>{{ $t('wo2_workorders_show.margin') }}</span><span class="font-mono" data-margin>{{ money(preview.margin) }}</span></p>
                    <p v-if="o.invoice" class="pt-2 border-t border-slate-100">{{ $t('wo2_workorders_show.invoice') }} <Link :href="route('sales.invoices.show', o.invoice.id)" class="font-mono font-bold text-indigo-600" data-invoice>{{ o.invoice.invoice_number }}</Link></p>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                    <h2 class="font-bold">{{ $t('wo2_workorders_show.customer_signature') }}</h2>
                    <template v-if="o.signature_url"><img :src="o.signature_url" alt="" class="border rounded-lg bg-white" data-signature-img /><p class="text-slate-500">{{ $t('wo2_workorders_show.signed_by_n_n', { n0: o.signed_by, n1: when(o.signed_at) }) }}</p></template>
                    <p v-else-if="!open" class="text-slate-400">{{ $t('wo2_workorders_show.not_signed') }}</p>
                    <template v-if="open && $can('work_orders.orders.edit')"><input v-model="sign.name" :placeholder="$t('wo2_workorders_show.signer_s_name')" :class="input" class="w-full" /><SignaturePad v-model="sign.signature" /><button type="button" :disabled="!sign.signature || !sign.name" class="w-full py-1.5 rounded-lg border border-indigo-200 text-indigo-700 font-bold disabled:opacity-40" @click="doSign">{{ $t('wo2_workorders_show.save_the_signature') }}</button></template>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                    <h2 class="font-bold">{{ $t('wo2_workorders_show.photos_and_attachments') }}</h2>
                    <a v-for="a in attachments" :key="a.id" :href="a.url" target="_blank" class="block text-indigo-600 font-bold truncate">{{ a.name }}</a>
                    <label v-if="$can('work_orders.orders.edit')" class="inline-block px-3 py-1.5 rounded-lg border border-slate-200 font-bold text-slate-600 cursor-pointer">{{ $t('wo2_workorders_show.upload_a_file') }}<input type="file" accept="image/*,application/pdf" class="sr-only" @change="upload" /></label>
                </div>
            </div>
        </div>

        <Dialog :show="completing" :title="$t('wo2_workorders_show.complete_the_work_order')" @close="completing = false">
            <textarea v-model="done.notes" rows="3" :placeholder="$t('wo2_workorders_show.completion_notes')" class="w-full rounded-xl border-slate-200 text-xs"></textarea>
            <p v-if="done.errors.timer || done.errors.tasks || done.errors.signature" class="text-rose-600 text-xs font-bold mt-1" data-complete-error>{{ done.errors.timer || done.errors.tasks || done.errors.signature }}</p>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="completing = false">{{ $t('wo2_workorders_show.back') }}</button><button type="button" :disabled="done.processing" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" @click="doComplete" data-confirm-complete>{{ $t('wo2_workorders_show.confirm_completion') }}</button></template>
        </Dialog>
        <Dialog :show="cancelling" :title="$t('wo2_workorders_show.cancel_the_work_order')" @close="cancelling = false">
            <input v-model="cancel.reason" :placeholder="$t('wo2_workorders_show.reason_optional')" class="w-full rounded-xl border-slate-200 text-xs" />
            <p class="text-3xs text-slate-500 mt-1">{{ $t('wo2_workorders_show.issued_parts_return_to_the_wareh') }}</p>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="cancelling = false">{{ $t('wo2_workorders_show.back') }}</button><button type="button" :disabled="cancel.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" @click="doCancel" data-confirm-cancel>{{ $t('wo2_workorders_show.confirm_cancellation') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

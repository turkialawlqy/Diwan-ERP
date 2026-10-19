<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import ProductPicker from '@/Components/Documents/ProductPicker.vue';
import MaintenanceStatus from '@/Components/Equipment/MaintenanceStatus.vue';
import { money, qty, date } from '@/utils/format';

const props = defineProps({ order: Object, selected: Array, schedules: Array, warehouses: Array, stock: Object });
const o = computed(() => props.order);
const e = computed(() => props.order.equipment);
const isOpen = computed(() => o.value.status === 'open');
const usesHours = computed(() => ['hours', 'both'].includes(e.value.meter_type));
const usesKm = computed(() => ['km', 'both'].includes(e.value.meter_type));

// --- storekeeper issue
const stockOf = ref({ ...props.stock });
const issueForm = useForm({ warehouse_id: props.order.warehouse_id || props.warehouses[0]?.id || '', date: new Date().toLocaleDateString('en-CA'), lines: [] });
const products = ref({});
const addLine = (line = {}) => issueForm.lines.push({ product_id: null, quantity: 1, schedule_id: null, ...line });
const fromSchedules = () => {
    props.schedules.filter((s) => s.product_id && checked.value.includes(s.id) && !issueForm.lines.some((l) => l.schedule_id === s.id))
        .forEach((s) => { products.value[s.product_id] = s.product; addLine({ product_id: s.product_id, quantity: s.default_quantity, schedule_id: s.id }); });
};
const onPick = async (i, p) => {
    if (!p) return;
    products.value[p.id] = p;
    try {
        const { data } = await axios.get(route('equipment.maintenance.stock'), { params: { product_id: p.id } });
        stockOf.value = { ...stockOf.value, [p.id]: data.stock };
    } catch { /* shown as unknown */ }
};
const onHand = (productId) => productId ? Number(stockOf.value[productId]?.[issueForm.warehouse_id] ?? 0) : null;
const issue = () => issueForm.post(route('equipment.maintenance.issue', o.value.id), { preserveScroll: true, onSuccess: () => { issueForm.lines = []; } });

// --- complete
const checked = ref([...props.selected]);
const completeForm = useForm({ schedule_ids: [], hours_reading: props.order.hours_reading ?? '', km_reading: props.order.km_reading ?? '', date: new Date().toLocaleDateString('en-CA'), notes: props.order.notes || '' });
const complete = () => {
    completeForm.schedule_ids = checked.value;
    completeForm.post(route('equipment.maintenance.complete', o.value.id), { preserveScroll: true });
};
const issuedFor = (id) => props.order.items.some((i) => i.schedule_id === id);
const remove = () => confirm('حذف أمر الصيانة؟') && router.delete(route('equipment.maintenance.destroy', o.value.id));
</script>

<template>
    <AppLayout>
        <Head :title="`أمر صيانة ${o.number}`" />
        <PageHeader :title="`أمر صيانة ${o.number}`" :subtitle="`${e.code} — ${e.name} · ${o.type === 'preventive' ? 'صيانة دورية' : 'إصلاح عطل'} · ${date(o.date)}`" :crumbs="[{ label: 'أوامر الصيانة', href: route('equipment.maintenance.index') }, { label: o.number }]">
            <template #actions>
                <span :class="['px-3 py-1.5 rounded-xl text-xs font-bold', isOpen ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700']">{{ isOpen ? 'مفتوح' : `مكتمل ${date(o.completed_at)}` }}</span>
                <Link :href="route('equipment.show', e.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">بطاقة المعدة</Link>
                <button v-if="isOpen && !o.items.length && $can('equipment.maintenance.delete')" @click="remove" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">حذف</button>
            </template>
        </PageHeader>

        <p v-if="o.technician || o.notes" class="mb-4 text-xs text-slate-600"><template v-if="o.technician"><b>الفني:</b> {{ o.technician }}. </template>{{ o.notes }}</p>

        <div class="grid lg:grid-cols-2 gap-4 text-xs">
            <!-- Parts issued -->
            <section class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                <div class="flex items-center justify-between"><h3 class="font-black text-slate-800">قطع الغيار المصروفة</h3><span class="font-mono font-bold">{{ money(o.parts_cost) }}</span></div>
                <div class="overflow-x-auto"><table class="w-full">
                    <thead class="text-slate-400"><tr><th class="py-1 text-start">الصنف</th><th class="py-1 text-start">الكمية</th><th class="py-1 text-start">التكلفة</th><th class="py-1 text-start">المستودع / أمين المستودع</th></tr></thead>
                    <tbody>
                        <tr v-for="i in o.items" :key="i.id" class="border-t border-slate-100">
                            <td class="py-1.5 font-bold">{{ i.product?.name_ar }}</td>
                            <td class="py-1.5 font-mono">{{ qty(i.quantity) }}</td>
                            <td class="py-1.5 font-mono">{{ money(i.amount) }}</td>
                            <td class="py-1.5 text-slate-500">{{ i.warehouse?.name_ar }} · {{ i.issuer?.name }}</td>
                        </tr>
                        <tr v-if="!o.items.length"><td colspan="4" class="py-4 text-center text-slate-400">لم تُصرف أصناف بعد.</td></tr>
                    </tbody>
                </table></div>

                <div v-if="isOpen && $can('equipment.maintenance.issue')" class="border-t border-dashed border-slate-200 pt-3 space-y-2">
                    <div class="flex flex-wrap items-end gap-2">
                        <h4 class="font-black text-slate-700 flex-1">صرف من المستودع</h4>
                        <Field label="المستودع" :error="issueForm.errors.warehouse_id"><select v-model="issueForm.warehouse_id" class="rounded-xl border-slate-200 text-xs"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                        <Field label="تاريخ الصرف"><input v-model="issueForm.date" type="date" class="rounded-xl border-slate-200 text-xs" /></Field>
                    </div>
                    <div v-for="(l, i) in issueForm.lines" :key="i" class="grid grid-cols-12 gap-2 items-start">
                        <div class="col-span-7"><ProductPicker v-model="l.product_id" :initial="products[l.product_id] || null" @select="onPick(i, $event)" /></div>
                        <div class="col-span-3"><input v-model="l.quantity" type="number" min="0.001" step="0.001" :aria-label="`كمية السطر ${i + 1}`" :class="['w-full rounded-xl text-xs font-mono', issueForm.errors[`lines.${i}.quantity`] ? 'border-rose-400' : 'border-slate-200']" />
                            <div v-if="l.product_id" :class="['text-[10px] mt-0.5', onHand(l.product_id) < Number(l.quantity) ? 'text-rose-600 font-bold' : 'text-slate-400']">المتوفر: {{ qty(onHand(l.product_id)) }}</div></div>
                        <button type="button" @click="issueForm.lines.splice(i, 1)" class="col-span-2 py-2 text-rose-600 font-bold">حذف</button>
                    </div>
                    <p v-if="issueForm.errors.items || issueForm.errors.lines" class="text-rose-600 font-bold">{{ issueForm.errors.items || issueForm.errors.lines }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="addLine()" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">+ صنف</button>
                        <button v-if="schedules.some((s) => s.product_id && checked.includes(s.id))" type="button" @click="fromSchedules" class="px-3 py-1.5 rounded-xl border border-indigo-200 text-indigo-700 font-bold">إضافة أصناف البنود المحددة</button>
                        <div class="flex-1"></div>
                        <button type="button" @click="issue" :disabled="issueForm.processing || !issueForm.lines.length" class="px-5 py-1.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-40">صرف وخصم من المخزون</button>
                    </div>
                </div>
            </section>

            <!-- Periodic items -->
            <section class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                <h3 class="font-black text-slate-800">بنود الصيانة الدورية المنفذة</h3>
                <p v-if="isOpen" class="text-slate-500">حدد البنود التي نُفذت فعلاً؛ يُعاد احتساب موعدها القادم من قراءة الإكمال.</p>
                <div class="space-y-2">
                    <label v-for="s in schedules" :key="s.id" :class="['flex items-start gap-3 p-2 rounded-xl border', checked.includes(s.id) ? 'border-indigo-300 bg-indigo-50/50' : 'border-slate-100']">
                        <input type="checkbox" :value="s.id" v-model="checked" :disabled="!isOpen" class="mt-1 rounded border-slate-300" />
                        <div class="flex-1">
                            <div class="font-bold text-slate-800">{{ s.name }} <span v-if="s.product" class="font-normal text-slate-400">· {{ s.product.name_ar }} × {{ Number(s.default_quantity) }}</span> <span v-if="issuedFor(s.id)" class="text-emerald-600">· صُرف</span></div>
                            <MaintenanceStatus v-if="isOpen" :status="s.status" class="mt-1" />
                        </div>
                    </label>
                    <p v-if="!schedules.length" class="text-slate-400">لا توجد بنود دورية لهذه المعدة. <Link :href="route('equipment.show', e.id)" class="text-indigo-600">أضفها من بطاقة المعدة</Link>.</p>
                </div>

                <div v-if="isOpen && $can('equipment.maintenance.complete')" class="border-t border-dashed border-slate-200 pt-3 grid grid-cols-2 gap-3">
                    <Field v-if="usesHours" label="عداد الساعات عند الإكمال" :error="completeForm.errors.hours_reading"><input v-model="completeForm.hours_reading" type="number" min="0" step="0.1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="usesKm" label="الكيلومترات عند الإكمال" :error="completeForm.errors.km_reading"><input v-model="completeForm.km_reading" type="number" min="0" step="0.1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="تاريخ الإكمال"><input v-model="completeForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="ملاحظات الفني" class="col-span-2"><textarea v-model="completeForm.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                    <p v-if="completeForm.errors.status" class="col-span-2 text-rose-600">{{ completeForm.errors.status }}</p>
                    <button type="button" @click="complete" :disabled="completeForm.processing" class="col-span-2 py-2 rounded-xl bg-emerald-600 text-white font-bold disabled:opacity-50">إكمال الصيانة ({{ checked.length }} بند)</button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

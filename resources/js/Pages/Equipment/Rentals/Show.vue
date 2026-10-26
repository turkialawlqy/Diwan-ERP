<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ rental: Object, readings: Array, summary: Object, suggest: Object, bases: Object });
const r = props.rental;
const unit = props.bases[r.billing_basis];
const isCustomer = r.type === 'customer';

// --- bill a period
const billOpen = ref(false);
const billForm = useForm({ period_from: props.suggest.from, period_to: props.suggest.to, quantity: '', date: '' });
const preview = ref(null);
const loadPreview = async () => {
    preview.value = null;
    if (!billForm.period_from || !billForm.period_to) return;
    try {
        const { data } = await axios.get(route('equipment.rentals.preview', r.id), { params: { period_from: billForm.period_from, period_to: billForm.period_to } });
        preview.value = data;
    } catch (e) { preview.value = { error: e.response?.data?.message || 'تعذّر الحساب' }; }
};
const openBill = () => { billForm.clearErrors(); billForm.quantity = ''; billOpen.value = true; loadPreview(); };
const bill = () => billForm.post(route('equipment.rentals.bill', r.id), { preserveScroll: true, onSuccess: () => { billOpen.value = false; } });

// --- edit / close
const editOpen = ref(false);
const editForm = useForm({ rate: r.rate, min_hours_per_day: r.min_hours_per_day ?? '', end_date: r.end_date?.substring(0, 10) || '', site: r.site || '', with_operator: r.with_operator, notes: r.notes || '' });
const saveEdit = () => editForm.put(route('equipment.rentals.update', r.id), { preserveScroll: true, onSuccess: () => { editOpen.value = false; } });
const closeOpen = ref(false);
const closeForm = useForm({ end_date: r.end_date?.substring(0, 10) || new Date().toLocaleDateString('en-CA') });
const close = () => closeForm.post(route('equipment.rentals.close', r.id), { preserveScroll: true, onSuccess: () => { closeOpen.value = false; } });
</script>

<template>
    <AppLayout>
        <Head :title="`عقد ${r.number}`" />
        <PageHeader :title="`عقد ${r.number}`" :subtitle="isCustomer ? `تأجير لـ ${r.customer?.name}` : `تشغيل في مشروع ${r.project?.code} — ${r.project?.name}`" :crumbs="[{ label: 'عقود التأجير', href: route('equipment.rentals.index') }, { label: r.number }]">
            <template #actions>
                <button v-if="$can('equipment.rentals.bill')" @click="openBill" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ isCustomer ? 'إصدار فاتورة لفترة' : 'تحميل فترة على المشروع' }}</button>
                <button v-if="$can('equipment.rentals.edit')" @click="editOpen = true" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">تعديل</button>
                <button v-if="$can('equipment.rentals.edit') && r.status === 'active'" @click="closeOpen = true" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">إنهاء العقد</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">المعدة</div><Link :href="route('equipment.show', r.equipment.id)" class="font-bold text-indigo-700">{{ r.equipment.code }} — {{ r.equipment.name }}</Link></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">السعر</div><div class="font-bold">{{ money(r.rate) }} / {{ unit }}</div><div v-if="r.min_hours_per_day" class="text-slate-400">حد أدنى {{ Number(r.min_hours_per_day) }} س/يوم</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">الفترة</div><div class="font-bold">{{ date(r.start_date) }} — {{ r.end_date ? date(r.end_date) : 'مفتوح' }}</div><div :class="r.status === 'active' ? 'text-emerald-600' : 'text-slate-400'">{{ r.status === 'active' ? 'نشط' : 'منتهٍ' }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">ساعات العمل المسجلة</div><div class="font-mono font-black text-lg">{{ summary.worked_hours }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ isCustomer ? 'إجمالي المفوتر' : 'إجمالي المحمّل' }}</div><div class="font-mono font-black text-lg">{{ money(summary.billed) }}</div><div class="text-slate-400">مفوتر حتى {{ r.billed_until ? date(r.billed_until) : '—' }}</div></div>
        </div>
        <p v-if="r.site || r.notes" class="mb-4 text-xs text-slate-500"><template v-if="r.site">الموقع: {{ r.site }}. </template>{{ r.notes }}</p>

        <div class="grid lg:grid-cols-2 gap-4 text-xs">
            <section>
                <h3 class="font-black text-slate-700 mb-2">الفترات المفوترة</h3>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الفترة</th><th class="px-3 py-2 text-start">الكمية</th><th class="px-3 py-2 text-start">المبلغ</th><th class="px-3 py-2 text-start">المستند</th></tr></thead>
                        <tbody>
                            <tr v-for="b in rental.billings" :key="b.id" class="border-t border-slate-100">
                                <td class="px-3 py-2 whitespace-nowrap">{{ date(b.period_from) }} — {{ date(b.period_to) }}</td>
                                <td class="px-3 py-2">{{ Number(b.quantity) }} {{ unit }}</td>
                                <td class="px-3 py-2 font-mono">{{ money(Number(b.amount) + Number(b.tax_amount)) }}</td>
                                <td class="px-3 py-2"><Link v-if="b.invoice" :href="route('sales.invoices.show', b.invoice.id)" class="text-indigo-600 font-mono">{{ b.invoice.invoice_number }}</Link><span v-else class="text-slate-400">تكلفة مشروع</span></td>
                            </tr>
                            <tr v-if="!rental.billings.length"><td colspan="4" class="px-3 py-6 text-center text-slate-400">لم تُفوتر أي فترة بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section>
                <h3 class="font-black text-slate-700 mb-2">القراءات اليومية على هذا العقد</h3>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto max-h-96">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500 sticky top-0"><tr><th class="px-3 py-2 text-start">التاريخ</th><th class="px-3 py-2 text-start">العداد</th><th class="px-3 py-2 text-start">ساعات العمل</th><th class="px-3 py-2 text-start">المشغّل</th></tr></thead>
                        <tbody>
                            <tr v-for="x in readings" :key="x.id" class="border-t border-slate-100">
                                <td class="px-3 py-2">{{ date(x.date) }}</td>
                                <td class="px-3 py-2 font-mono">{{ x.hours_reading ?? '' }}{{ x.km_reading ? ` / ${Number(x.km_reading).toLocaleString('en-US')} كم` : '' }}</td>
                                <td class="px-3 py-2 font-mono">{{ Number(x.worked_hours) }}<span v-if="r.min_hours_per_day && Number(x.worked_hours) < r.min_hours_per_day" class="text-amber-600"> → {{ Number(r.min_hours_per_day) }}</span></td>
                                <td class="px-3 py-2">{{ x.operator || '' }}</td>
                            </tr>
                            <tr v-if="!readings.length"><td colspan="4" class="px-3 py-6 text-center text-slate-400">لا توجد قراءات بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Dialog :show="billOpen" :title="isCustomer ? 'إصدار فاتورة تأجير' : 'تحميل على المشروع'" size="md" @close="billOpen = false">
            <div class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <Field label="من" :error="billForm.errors.period_from"><input v-model="billForm.period_from" @change="loadPreview" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="إلى" :error="billForm.errors.period_to"><input v-model="billForm.period_to" @change="loadPreview" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <div v-if="preview" class="rounded-xl bg-slate-50 border border-slate-200 p-3 space-y-1">
                    <p v-if="preview.error" class="text-rose-600">{{ preview.error }}</p>
                    <template v-else>
                        <p v-if="r.billing_basis === 'hourly'">ساعات العمل المسجلة: <b>{{ preview.worked_hours }}</b> في {{ preview.days_with_readings }} يوم<template v-if="r.min_hours_per_day"> · بعد تطبيق الحد الأدنى: <b>{{ preview.quantity }}</b></template></p>
                        <p>الكمية: <b>{{ preview.quantity }} {{ unit }}</b> × {{ money(preview.rate) }} = <b>{{ money(preview.amount) }}</b><template v-if="preview.tax"> + ضريبة {{ money(preview.tax) }} = <b>{{ money(preview.total) }}</b></template></p>
                        <p class="text-slate-400">الفترة بعد مطابقتها مع مدة العقد: {{ preview.from }} — {{ preview.to }}</p>
                        <p v-if="preview.overlap" class="text-rose-600 font-bold">هذه الفترة تتداخل مع فترة مفوترة سابقاً ({{ date(preview.overlap.period_from) }} — {{ date(preview.overlap.period_to) }}).</p>
                    </template>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Field :label="`تعديل الكمية (${unit})`" :error="billForm.errors.quantity" hint="اتركه فارغاً لاعتماد المحسوب"><input v-model="billForm.quantity" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="تاريخ المستند" hint="فارغ = نهاية الفترة"><input v-model="billForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
            </div>
            <template #footer>
                <button type="button" @click="billOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="bill" :disabled="billForm.processing || preview?.overlap" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ isCustomer ? 'إصدار الفاتورة' : 'تحميل' }}</button>
            </template>
        </Dialog>

        <Dialog :show="editOpen" title="تعديل العقد" size="md" @close="editOpen = false">
            <div class="grid grid-cols-2 gap-3 text-xs">
                <Field :label="`السعر لكل ${unit}`" :error="editForm.errors.rate" hint="ينطبق على الفترات التي لم تُفوتر"><input v-model="editForm.rate" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="r.billing_basis === 'hourly'" label="حد أدنى ساعات/يوم" :error="editForm.errors.min_hours_per_day"><input v-model="editForm.min_hours_per_day" type="number" min="0" max="24" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تاريخ النهاية" :error="editForm.errors.end_date"><input v-model="editForm.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الموقع"><input v-model="editForm.site" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="col-span-2 flex items-center gap-2 font-bold"><input v-model="editForm.with_operator" type="checkbox" class="rounded border-slate-300" /> مع مشغّل</label>
                <Field label="ملاحظات" class="col-span-2"><textarea v-model="editForm.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <template #footer>
                <button type="button" @click="editOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="saveEdit" :disabled="editForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">حفظ</button>
            </template>
        </Dialog>

        <Dialog :show="closeOpen" title="إنهاء العقد" size="sm" @close="closeOpen = false">
            <div class="text-xs space-y-3">
                <p class="text-slate-600">تعود المعدة «متاحة» بعد الإنهاء. تأكد من فوترة الفترة الأخيرة.</p>
                <Field label="آخر يوم في العقد" :error="closeForm.errors.end_date"><input v-model="closeForm.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <template #footer>
                <button type="button" @click="closeOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="close" :disabled="closeForm.processing" class="px-5 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold disabled:opacity-50">إنهاء</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

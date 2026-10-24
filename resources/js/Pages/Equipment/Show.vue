<script setup>
import { taxRate } from '@/utils/tax';
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import EquipmentForm from '@/Components/Equipment/EquipmentForm.vue';
import MaintenanceStatus from '@/Components/Equipment/MaintenanceStatus.vue';
import ProductPicker from '@/Components/Documents/ProductPicker.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ equipment: Object, schedules: Array, readings: Array, rentals: Array, orders: Array, billings: Array, profit: Object, profitFrom: String, others: Array, statuses: Object, lookups: Object });
const e = computed(() => props.equipment);
const tab = ref('schedules');
const tabs = { schedules: 'جدول الصيانة الدورية', readings: 'القراءات', rentals: 'العقود', orders: 'سجل الصيانة', money: 'الإيرادات والتكاليف' };
const usesHours = computed(() => ['hours', 'both'].includes(e.value.meter_type));
const usesKm = computed(() => ['km', 'both'].includes(e.value.meter_type));
const statusColors = { available: 'bg-emerald-50 text-emerald-700', rented: 'bg-indigo-50 text-indigo-700', internal: 'bg-sky-50 text-sky-700', maintenance: 'bg-amber-50 text-amber-700', out_of_service: 'bg-slate-100 text-slate-500' };

// --- edit equipment
const fields = ['code', 'name', 'category', 'model', 'plate_number', 'serial_number', 'ownership', 'fixed_asset_id', 'supplier_id', 'supplier_rate', 'supplier_rate_basis',
    'meter_type', 'current_hours', 'current_km', 'hourly_rate', 'daily_rate', 'monthly_rate', 'internal_hourly_cost', 'cost_center_id', 'notes'];
const editForm = useForm({});
const editing = ref(false);
const edit = () => {
    const data = Object.fromEntries(fields.map((f) => [f, e.value[f] ?? (f.endsWith('_id') || f === 'supplier_rate_basis' ? null : '')]));
    editForm.defaults({ ...data, out_of_service: e.value.status === 'out_of_service' }).reset();
    editForm.clearErrors();
    editing.value = true;
};
const saveEdit = () => editForm.put(route('equipment.update', e.value.id), { preserveScroll: true, onSuccess: () => { editing.value = false; } });
const remove = () => confirm(`حذف المعدة «${e.value.name}»؟`) && router.delete(route('equipment.destroy', e.value.id));

// --- schedules
const blankSchedule = () => ({ name: '', product_id: null, default_quantity: 1, interval_hours: '', interval_km: '', interval_days: '', warn_hours: '', warn_km: '', warn_days: '',
    last_done_date: '', last_done_hours: '', last_done_km: '', is_active: true });
const scheduleForm = useForm(blankSchedule());
const scheduleOpen = ref(false);
const scheduleEditing = ref(null);
const scheduleProduct = ref(null);
const newSchedule = () => { scheduleEditing.value = null; scheduleProduct.value = null; scheduleForm.defaults(blankSchedule()).reset(); scheduleForm.clearErrors(); scheduleOpen.value = true; };
const editSchedule = (s) => {
    scheduleEditing.value = s;
    scheduleProduct.value = s.product;
    scheduleForm.defaults(Object.fromEntries(Object.keys(blankSchedule()).map((k) => [k, k === 'last_done_date' ? (s[k] || '').substring(0, 10) : (s[k] ?? '')]))).reset();
    scheduleForm.clearErrors();
    scheduleOpen.value = true;
};
const saveSchedule = () => {
    const options = { preserveScroll: true, onSuccess: () => { scheduleOpen.value = false; } };
    scheduleEditing.value ? scheduleForm.put(route('equipment.schedules.update', scheduleEditing.value.id), options) : scheduleForm.post(route('equipment.schedules.store', e.value.id), options);
};
const removeSchedule = (s) => confirm(`حذف بند «${s.name}»؟`) && router.delete(route('equipment.schedules.destroy', s.id), { preserveScroll: true });
const copyFrom = ref('');
const copySchedules = () => copyFrom.value && router.post(route('equipment.schedules.copy', e.value.id), { from_id: copyFrom.value }, { preserveScroll: true, onSuccess: () => { copyFrom.value = ''; } });
const interval = (s) => [s.interval_hours && `${Number(s.interval_hours)} ساعة`, s.interval_km && `${Number(s.interval_km).toLocaleString('en-US')} كم`, s.interval_days && `${s.interval_days} يوم`].filter(Boolean).join(' أو ');
const dueCount = computed(() => props.schedules.filter((s) => s.status.state !== 'ok' && s.is_active).length);

// --- supplier rent (hired-in)
const supplierOpen = ref(false);
const supplierForm = useForm({ period_from: '', period_to: '', quantity: '', tax_rate: taxRate(), reference: '' });
const supplierPreview = ref(null);
const openSupplier = () => {
    const now = new Date();
    supplierForm.period_from = new Date(now.getFullYear(), now.getMonth() - 1, 1).toLocaleDateString('en-CA');
    supplierForm.period_to = new Date(now.getFullYear(), now.getMonth(), 0).toLocaleDateString('en-CA');
    supplierForm.clearErrors();
    supplierOpen.value = true;
    loadSupplierPreview();
};
const loadSupplierPreview = async () => {
    supplierPreview.value = null;
    if (!supplierForm.period_from || !supplierForm.period_to) return;
    try {
        const { data } = await axios.get(route('equipment.supplier.preview', e.value.id), { params: { period_from: supplierForm.period_from, period_to: supplierForm.period_to, tax_rate: supplierForm.tax_rate } });
        supplierPreview.value = data;
    } catch (err) { supplierPreview.value = { error: err.response?.data?.message || 'تعذّر الحساب' }; }
};
const saveSupplier = () => supplierForm.post(route('equipment.supplier.bill', e.value.id), { preserveScroll: true, onSuccess: () => { supplierOpen.value = false; } });
</script>

<template>
    <AppLayout>
        <Head :title="e.name" />
        <PageHeader :title="`${e.name}`" :subtitle="[e.code, e.category, e.model, e.plate_number].filter(Boolean).join(' · ')" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: e.code }]">
            <template #actions>
                <Link v-if="$can('equipment.maintenance.create')" :href="route('equipment.maintenance.index', { equipment_id: e.id })" class="px-4 py-2 rounded-xl bg-amber-500 text-white text-xs font-bold">أمر صيانة</Link>
                <Link v-if="$can('equipment.rentals.create') && e.status !== 'out_of_service'" :href="route('equipment.rentals.index', { equipment: e.id })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">عقد تأجير</Link>
                <button v-if="$can('equipment.rentals.bill') && e.ownership === 'rented_in'" @click="openSupplier" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">تسجيل إيجار المورد</button>
                <button v-if="$can('equipment.assets.edit')" @click="edit" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">تعديل</button>
                <button v-if="$can('equipment.assets.delete')" @click="remove" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">حذف</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">الحالة</div><span :class="[statusColors[e.status], 'inline-block mt-1 px-2 py-0.5 rounded-full font-bold']">{{ statuses[e.status] }}</span></div>
            <div v-if="usesHours" class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">عداد الساعات</div><div class="font-mono font-black text-lg text-slate-800">{{ Number(e.current_hours).toLocaleString('en-US') }}</div></div>
            <div v-if="usesKm" class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">عداد الكيلومترات</div><div class="font-mono font-black text-lg text-slate-800">{{ Number(e.current_km).toLocaleString('en-US') }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">بنود صيانة مستحقة</div><div :class="['font-black text-lg', dueCount ? 'text-rose-600' : 'text-emerald-600']">{{ dueCount }}</div></div>
            <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">صافي الربح (12 شهراً)</div><div :class="['font-black text-lg font-mono', (profit?.net || 0) < 0 ? 'text-rose-600' : 'text-slate-800']">{{ money(profit?.net || 0) }}</div></div>
        </div>
        <p v-if="e.ownership === 'rented_in'" class="mb-3 text-xs text-slate-500">مستأجرة من <b>{{ e.supplier?.name }}</b> بسعر {{ money(e.supplier_rate) }} لكل {{ lookups.bases[e.supplier_rate_basis] }}</p>

        <div class="flex flex-wrap gap-1 border-b border-slate-200 mb-4 text-xs" role="tablist">
            <button v-for="(l, k) in tabs" :key="k" role="tab" :aria-selected="tab === k" @click="tab = k" :class="['px-4 py-2 font-bold border-b-2 -mb-px', tab === k ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500']">{{ l }}</button>
        </div>

        <!-- Maintenance plan -->
        <section v-if="tab === 'schedules'" class="space-y-3 text-xs">
            <div v-if="$can('equipment.assets.edit')" class="flex flex-wrap items-center gap-2">
                <button @click="newSchedule" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">+ بند صيانة دورية</button>
                <template v-if="others.length">
                    <span class="text-slate-400 ms-3">أو انسخ الجدول من:</span>
                    <select v-model="copyFrom" class="rounded-xl border-slate-200 text-xs" aria-label="نسخ الجدول من معدة"><option value="">اختر معدة</option><option v-for="o in others" :key="o.id" :value="o.id">{{ o.code }} — {{ o.name }}{{ o.model ? ` (${o.model})` : '' }}</option></select>
                    <button @click="copySchedules" :disabled="!copyFrom" class="px-3 py-2 rounded-xl border border-slate-200 font-bold disabled:opacity-40">نسخ</button>
                </template>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">البند</th><th class="px-3 py-2 text-start">الصنف من المستودع</th><th class="px-3 py-2 text-start">كل</th><th class="px-3 py-2 text-start">آخر تنفيذ</th><th class="px-3 py-2 text-start w-64">الحالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in schedules" :key="s.id" :class="['border-t border-slate-100', !s.is_active && 'opacity-50']">
                            <td class="px-3 py-2 font-bold text-slate-800">{{ s.name }}</td>
                            <td class="px-3 py-2">{{ s.product?.name_ar || '—' }}<span v-if="s.product" class="text-slate-400"> × {{ Number(s.default_quantity) }}</span></td>
                            <td class="px-3 py-2">{{ interval(s) }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ date(s.last_done_date) }}<span v-if="s.last_done_hours !== null && usesHours"> · {{ Number(s.last_done_hours) }} س</span><span v-if="s.last_done_km !== null && usesKm"> · {{ Number(s.last_done_km).toLocaleString('en-US') }} كم</span></td>
                            <td class="px-3 py-2"><MaintenanceStatus :status="s.status" /></td>
                            <td class="px-3 py-2 whitespace-nowrap text-end">
                                <template v-if="$can('equipment.assets.edit')"><button @click="editSchedule(s)" class="text-indigo-600 font-bold me-3">تعديل</button><button @click="removeSchedule(s)" class="text-rose-600 font-bold">حذف</button></template>
                            </td>
                        </tr>
                        <tr v-if="!schedules.length"><td colspan="6" class="px-3 py-8 text-center text-slate-400">لا توجد بنود صيانة دورية. أضف مثلاً: «زيت المحرك كل 250 ساعة أو 90 يوماً»، «فلتر الهواء كل 500 ساعة»، «الإطارات كل 40,000 كم».</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Readings -->
        <section v-if="tab === 'readings'" class="text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">التاريخ</th><th v-if="usesHours" class="px-3 py-2 text-start">عداد الساعات</th><th v-if="usesKm" class="px-3 py-2 text-start">الكيلومترات</th><th class="px-3 py-2 text-start">ساعات العمل</th><th class="px-3 py-2 text-start">المشغّل</th><th class="px-3 py-2 text-start">العقد</th></tr></thead>
                    <tbody>
                        <tr v-for="r in readings" :key="r.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ date(r.date) }}</td>
                            <td v-if="usesHours" class="px-3 py-2 font-mono">{{ r.hours_reading ?? '—' }}</td>
                            <td v-if="usesKm" class="px-3 py-2 font-mono">{{ r.km_reading !== null ? Number(r.km_reading).toLocaleString('en-US') : '—' }}</td>
                            <td class="px-3 py-2 font-mono">{{ Number(r.worked_hours) }}</td>
                            <td class="px-3 py-2">{{ r.operator || '—' }}</td>
                            <td class="px-3 py-2"><Link v-if="r.rental" :href="route('equipment.rentals.show', r.rental.id)" class="text-indigo-600 font-mono">{{ r.rental.number }}</Link></td>
                        </tr>
                        <tr v-if="!readings.length"><td colspan="6" class="px-3 py-8 text-center text-slate-400">لا توجد قراءات. تُدخل يومياً من شاشة «قراءات العدادات».</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Rentals -->
        <section v-if="tab === 'rentals'" class="text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">العقد</th><th class="px-3 py-2 text-start">الجهة</th><th class="px-3 py-2 text-start">السعر</th><th class="px-3 py-2 text-start">الفترة</th><th class="px-3 py-2 text-start">الحالة</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rentals" :key="r.id" class="border-t border-slate-100">
                            <td class="px-3 py-2"><Link :href="route('equipment.rentals.show', r.id)" class="text-indigo-600 font-mono font-bold">{{ r.number }}</Link></td>
                            <td class="px-3 py-2">{{ r.customer?.name || `مشروع ${r.project?.code} — ${r.project?.name}` }}</td>
                            <td class="px-3 py-2">{{ money(r.rate) }} / {{ lookups.bases[r.billing_basis] }}</td>
                            <td class="px-3 py-2">{{ date(r.start_date) }} — {{ r.end_date ? date(r.end_date) : 'مفتوح' }}</td>
                            <td class="px-3 py-2">{{ r.status === 'active' ? 'نشط' : 'منتهٍ' }}</td>
                        </tr>
                        <tr v-if="!rentals.length"><td colspan="5" class="px-3 py-8 text-center text-slate-400">لا توجد عقود.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Maintenance history -->
        <section v-if="tab === 'orders'" class="text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الأمر</th><th class="px-3 py-2 text-start">النوع</th><th class="px-3 py-2 text-start">التاريخ</th><th class="px-3 py-2 text-start">القراءة</th><th class="px-3 py-2 text-start">الأصناف</th><th class="px-3 py-2 text-start">تكلفة القطع</th><th class="px-3 py-2 text-start">الحالة</th></tr></thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id" class="border-t border-slate-100">
                            <td class="px-3 py-2"><Link :href="route('equipment.maintenance.show', o.id)" class="text-indigo-600 font-mono font-bold">{{ o.number }}</Link></td>
                            <td class="px-3 py-2">{{ o.type === 'preventive' ? 'وقائية' : 'إصلاح عطل' }}</td>
                            <td class="px-3 py-2">{{ date(o.date) }}</td>
                            <td class="px-3 py-2 font-mono">{{ o.hours_reading ?? '' }}{{ o.km_reading ? ` / ${Number(o.km_reading).toLocaleString('en-US')} كم` : '' }}</td>
                            <td class="px-3 py-2">{{ o.items_count }}</td>
                            <td class="px-3 py-2 font-mono">{{ money(o.parts_cost) }}</td>
                            <td class="px-3 py-2">{{ o.status === 'open' ? 'مفتوح' : 'مكتمل' }}</td>
                        </tr>
                        <tr v-if="!orders.length"><td colspan="7" class="px-3 py-8 text-center text-slate-400">لا توجد أوامر صيانة.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Money -->
        <section v-if="tab === 'money'" class="space-y-3 text-xs">
            <p class="text-slate-500">من {{ profitFrom }} حتى اليوم</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">إيراد التأجير للعملاء</div><div class="font-mono font-black text-emerald-700">{{ money(profit?.revenue || 0) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">المحمّل على المشاريع</div><div class="font-mono font-black text-emerald-700">{{ money(profit?.recovery || 0) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">الصيانة وقطع الغيار</div><div class="font-mono font-black text-rose-600">{{ money(profit?.maintenance || 0) }}</div></div>
                <div class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ e.ownership === 'rented_in' ? 'إيجار المورد' : 'الإهلاك' }}</div><div class="font-mono font-black text-rose-600">{{ money(e.ownership === 'rented_in' ? profit?.rent_in : profit?.depreciation) }}</div></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">النوع</th><th class="px-3 py-2 text-start">الفترة</th><th class="px-3 py-2 text-start">الكمية</th><th class="px-3 py-2 text-start">المبلغ</th><th class="px-3 py-2 text-start">المرجع</th></tr></thead>
                    <tbody>
                        <tr v-for="b in billings" :key="b.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ b.direction === 'in' ? 'إيجار مورد' : (b.rental?.type === 'project' ? 'تحميل مشروع' : 'فاتورة عميل') }}</td>
                            <td class="px-3 py-2">{{ date(b.period_from) }} — {{ date(b.period_to) }}</td>
                            <td class="px-3 py-2">{{ Number(b.quantity) }} {{ lookups.bases[b.unit] }}</td>
                            <td class="px-3 py-2 font-mono">{{ money(b.amount) }}</td>
                            <td class="px-3 py-2"><Link v-if="b.invoice" :href="route('sales.invoices.show', b.invoice.id)" class="text-indigo-600 font-mono">{{ b.invoice.invoice_number }}</Link><span v-else class="font-mono">{{ b.reference }}</span></td>
                        </tr>
                        <tr v-if="!billings.length"><td colspan="5" class="px-3 py-8 text-center text-slate-400">لا توجد فوترة بعد.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog :show="editing" title="تعديل المعدة" size="xl" @close="editing = false">
            <EquipmentForm :form="editForm" :lookups="lookups" :supplier="e.supplier" :meters-locked="readings.length > 0" />
            <label class="flex items-center gap-2 mt-3 text-xs font-bold text-slate-700"><input v-model="editForm.out_of_service" type="checkbox" class="rounded border-slate-300" /> خارج الخدمة (لا تظهر في التأجير ولا التنبيهات)</label>
            <template #footer>
                <button type="button" @click="editing = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="saveEdit" :disabled="editForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">حفظ</button>
            </template>
        </Dialog>

        <Dialog :show="scheduleOpen" :title="scheduleEditing ? 'تعديل بند الصيانة' : 'بند صيانة دورية'" size="lg" @close="scheduleOpen = false">
            <div class="space-y-3 text-xs">
                <div class="grid md:grid-cols-3 gap-3">
                    <Field label="اسم البند" :error="scheduleForm.errors.name" class="md:col-span-2"><input v-model="scheduleForm.name" placeholder="مثال: تغيير زيت المحرك" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="مفعّل"><label class="flex items-center gap-2 mt-2"><input v-model="scheduleForm.is_active" type="checkbox" class="rounded border-slate-300" /> يُتابع ويُنبَّه عليه</label></Field>
                    <Field label="الصنف المستخدم من المستودع (اختياري)" :error="scheduleForm.errors.product_id" class="md:col-span-2"><ProductPicker v-model="scheduleForm.product_id" :initial="scheduleProduct" /></Field>
                    <Field label="الكمية المعتادة" :error="scheduleForm.errors.default_quantity"><input v-model="scheduleForm.default_quantity" type="number" min="0" step="0.001" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <p class="font-bold text-slate-700">يستحق كل… (أيّها يأتي أولاً)</p>
                <div class="grid md:grid-cols-3 gap-3">
                    <Field v-if="usesHours" label="ساعات تشغيل" :error="scheduleForm.errors.interval_hours"><input v-model="scheduleForm.interval_hours" type="number" min="1" placeholder="250" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="usesKm" label="كيلومترات" :error="scheduleForm.errors.interval_km"><input v-model="scheduleForm.interval_km" type="number" min="1" placeholder="10000" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="أيام" :error="scheduleForm.errors.interval_days || (!usesHours && !usesKm ? scheduleForm.errors.interval_hours : '')"><input v-model="scheduleForm.interval_days" type="number" min="1" placeholder="90" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <p v-if="scheduleForm.errors.interval_hours && !usesHours" class="text-rose-600">{{ scheduleForm.errors.interval_hours }}</p>
                <p class="font-bold text-slate-700">التنبيه المبكر قبل الموعد بـ <span class="font-normal text-slate-400">(فارغ = 10% من الفترة)</span></p>
                <div class="grid md:grid-cols-3 gap-3">
                    <Field v-if="usesHours" label="ساعات"><input v-model="scheduleForm.warn_hours" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="usesKm" label="كيلومترات"><input v-model="scheduleForm.warn_km" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="أيام"><input v-model="scheduleForm.warn_days" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <p class="font-bold text-slate-700">آخر تنفيذ <span class="font-normal text-slate-400">(فارغ = القراءة الحالية واليوم)</span></p>
                <div class="grid md:grid-cols-3 gap-3">
                    <Field label="التاريخ"><input v-model="scheduleForm.last_done_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="usesHours" label="عند قراءة ساعات"><input v-model="scheduleForm.last_done_hours" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="usesKm" label="عند قراءة كم"><input v-model="scheduleForm.last_done_km" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
            </div>
            <template #footer>
                <button type="button" @click="scheduleOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="saveSchedule" :disabled="scheduleForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">حفظ</button>
            </template>
        </Dialog>

        <Dialog :show="supplierOpen" title="تسجيل إيجار المورد" size="md" @close="supplierOpen = false">
            <div class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <Field label="من" :error="supplierForm.errors.period_from"><input v-model="supplierForm.period_from" @change="loadSupplierPreview" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="إلى" :error="supplierForm.errors.period_to"><input v-model="supplierForm.period_to" @change="loadSupplierPreview" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="`الكمية (${lookups.bases[e.supplier_rate_basis] || ''})`" :error="supplierForm.errors.quantity" hint="فارغ = المحسوب أدناه"><input v-model="supplierForm.quantity" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="نسبة الضريبة %"><input v-model="supplierForm.tax_rate" @change="loadSupplierPreview" type="number" min="0" max="100" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="رقم فاتورة المورد" class="col-span-2"><input v-model="supplierForm.reference" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <div v-if="supplierPreview" class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <p v-if="supplierPreview.error" class="text-rose-600">{{ supplierPreview.error }}</p>
                    <template v-else>
                        <p>الكمية المحسوبة: <b>{{ supplierPreview.quantity }}</b> {{ lookups.bases[e.supplier_rate_basis] }} × {{ money(supplierPreview.rate) }} = <b>{{ money(supplierPreview.amount) }}</b> + ضريبة {{ money(supplierPreview.tax) }}</p>
                        <p v-if="supplierPreview.overlap" class="text-rose-600 mt-1 font-bold">تنبيه: سُجّل إيجار لجزء من هذه الفترة سابقاً ({{ date(supplierPreview.overlap.period_from) }} — {{ date(supplierPreview.overlap.period_to) }}).</p>
                    </template>
                </div>
                <p v-if="supplierForm.errors.period_from || supplierForm.errors.equipment" class="text-rose-600">{{ supplierForm.errors.equipment }}</p>
            </div>
            <template #footer>
                <button type="button" @click="supplierOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="saveSupplier" :disabled="supplierForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">تسجيل</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

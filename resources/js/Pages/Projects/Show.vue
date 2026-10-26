<script setup>
import { taxRate } from '@/utils/tax';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import BoqEditor from '@/Components/Projects/BoqEditor.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ project: Object, summary: Object, boq: Array, billings: Array, variations: Array, subcontracts: Array, costs: Array, guarantees: Array, dailyReports: Array, bankAccounts: Array, warehouses: Array });
const today = new Date().toISOString().substring(0, 10);
const tab = ref('overview');
const tabs = [['overview', 'نظرة عامة'], ['boq', 'جدول الكميات'], ['billings', 'المستخلصات'], ['variations', 'أوامر التغيير'], ['subcontracts', 'مقاولو الباطن'], ['costs', 'التكاليف'], ['guarantees', 'خطابات الضمان'], ['daily', 'التقارير اليومية']];
const categoryLabel = { material: 'مواد', labour: 'عمالة', equipment: 'معدات', subcontract: 'مقاولو باطن', other: 'أخرى' };
const statusLabel = { planning: 'تخطيط', active: 'قيد التنفيذ', on_hold: 'متوقف', completed: 'منجز', closed: 'مغلق' };
const dialog = ref(null);
const close = () => (dialog.value = null);
const opts = { preserveScroll: true, onSuccess: close };

// BOQ
const boqForm = useForm({ boq: props.boq.filter((b) => !b.variation_id).map((b) => ({ code: b.code, parent_code: props.boq.find((p) => p.id === b.parent_id)?.code || null, description: b.description, unit: b.unit, quantity: Number(b.quantity), unit_price: Number(b.unit_price), unit_cost: Number(b.unit_cost), is_group: b.is_group })) });
const saveBoq = () => boqForm.put(route('projects.boq.update', props.project.id), { preserveScroll: true });
const billable = computed(() => props.boq.filter((b) => !b.is_group && Number(b.quantity) > 0));

// Billing
const billingForm = useForm({ billing_date: today, period_from: '', period_to: today, notes: '', lines: [] });
const openBilling = () => { billingForm.lines = billable.value.map((b) => ({ boq_item_id: b.id, code: b.code, description: b.description, contract: Number(b.quantity), billed: Number(b.billed_quantity), price: Number(b.unit_price), current_quantity: 0 })); dialog.value = 'billing'; };
const billingValue = computed(() => billingForm.lines.reduce((s, l) => s + Number(l.current_quantity || 0) * l.price, 0));
const saveBilling = () => billingForm.transform((d) => ({ ...d, lines: d.lines.filter((l) => Number(l.current_quantity) > 0).map(({ boq_item_id, current_quantity }) => ({ boq_item_id, current_quantity })) })).post(route('projects.billings.store', props.project.id));

// Advance / retention
const advanceForm = useForm({ date: today, amount: 0, payment_method: 'bank_transfer', bank_account_id: '' });
const retentionForm = useForm({ date: today, amount: props.summary.retention_open });

// Variations
const variationForm = useForm({ date: today, description: '', amount: 0, cost_impact: 0, time_impact_days: 0 });
const variationAct = (v, action) => router.post(route(`projects.variations.${action}`, [props.project.id, v.id]), {}, { preserveScroll: true });

// Subcontracts
const subForm = useForm({ supplier_id: null, scope: '', contract_value: 0, retention_percent: 10, start_date: '', end_date: '' });
const selectedSub = ref(null);
const subAdvanceForm = useForm({ date: today, amount: 0, payment_method: 'bank_transfer', bank_account_id: '' });
const certifyForm = useForm({ date: today, cumulative_value: 0, vat_rate: taxRate(), supplier_invoice_number: '' });
const openSub = (s, kind) => { selectedSub.value = s; certifyForm.cumulative_value = Number(s.certified_to_date); dialog.value = kind; };

// Costs
const costForm = useForm({ date: today, category: 'labour', description: '', amount: 0, vat_amount: 0, paid_from: 'accrued', supplier_id: null, bank_account_id: '', reference: '' });
const materialForm = useForm({ direction: 'issue', date: today, warehouse_id: props.project.warehouse_id || '', reference: '', lines: [] });
const lookup = ref('');
const results = ref([]);
const searchProducts = async () => { results.value = lookup.value.length < 2 ? [] : (await axios.get(route('lookup.products'), { params: { q: lookup.value } })).data.data; };
const addMaterial = (p) => { materialForm.lines.push({ product_id: p.id, name: p.name_ar, quantity: 1 }); results.value = []; lookup.value = ''; };

// Daily report & project details
const dailyForm = useForm({ date: today, weather: '', manpower: 0, equipment: '', work_done: '', issues: '' });
const editForm = useForm({ name: props.project.name, status: props.project.status, manager_id: props.project.manager_id, warehouse_id: props.project.warehouse_id, start_date: props.project.start_date?.substring(0, 10), end_date: props.project.end_date?.substring(0, 10), advance_percent: props.project.advance_percent, retention_percent: props.project.retention_percent, location: props.project.location });
</script>

<template>
    <AppLayout>
        <Head :title="project.name" />
        <PageHeader :title="`${project.code} — ${project.name}`" :subtitle="`${project.customer?.name} · ${statusLabel[project.status]} · مركز التكلفة ${project.cost_center?.code}`" :crumbs="[{ label: 'المقاولات' }, { label: 'المشاريع', href: route('projects.index') }, { label: project.code }]">
            <template #actions>
                <button v-if="$can('projects.finance.create')" @click="dialog = 'advance'" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">دفعة مقدمة</button>
                <button v-if="$can('projects.billings.create') && billable.length" @click="openBilling" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">مستخلص جديد</button>
                <button v-if="$can('projects.projects.edit')" @click="dialog = 'edit'" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">تعديل</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="قيمة العقد" :value="money(summary.contract_value)" />
            <StatCard label="المنجز المفوتر" :value="`${money(summary.billed_to_date)} (${summary.progress_percent}%)`" />
            <StatCard label="التكلفة الفعلية / التقديرية" :value="`${money(summary.actual_cost)} / ${money(summary.estimated_cost)}`" />
            <StatCard label="مجمل الربح" :value="`${money(summary.gross_profit)}${summary.margin_percent !== null ? ` (${summary.margin_percent}%)` : ''}`" />
        </div>

        <div class="flex flex-wrap gap-1 mb-3 border-b border-slate-200">
            <button v-for="[key, label] in tabs" :key="key" @click="tab = key" :class="tab === key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500'" class="px-3 py-2 text-xs font-bold border-b-2">{{ label }}</button>
        </div>

        <!-- Overview -->
        <div v-if="tab === 'overview'" class="grid md:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <h3 class="font-black text-slate-700 mb-2">العقد</h3>
                <div class="flex justify-between"><span>أوامر التغيير المعتمدة</span><b class="font-mono">{{ money(summary.approved_variations) }}</b></div>
                <div class="flex justify-between"><span>المتبقي للفوترة</span><b class="font-mono">{{ money(summary.remaining_to_bill) }}</b></div>
                <div class="flex justify-between"><span>الهامش المتوقع (حسب BOQ)</span><b class="font-mono">{{ summary.projected_margin ?? '—' }}%</b></div>
                <div class="flex justify-between"><span>الفترة</span><b class="font-mono">{{ date(project.start_date) }} → {{ date(project.end_date) }}</b></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <h3 class="font-black text-slate-700 mb-2">الدفعة المقدمة والمحتجزات</h3>
                <div class="flex justify-between"><span>المقدم المستلم</span><b class="font-mono">{{ money(project.advance_received) }}</b></div>
                <div class="flex justify-between"><span>المقدم غير المسترد</span><b class="font-mono">{{ money(summary.advance_open) }}</b></div>
                <div class="flex justify-between"><span>المحتجزات القائمة</span><b class="font-mono">{{ money(summary.retention_open) }}</b></div>
                <div class="flex justify-between"><span>المحصل</span><b class="font-mono">{{ money(summary.collected) }}</b></div>
                <button v-if="summary.retention_open > 0 && $can('projects.finance.approve')" @click="dialog = 'retention'" class="mt-2 text-indigo-600 font-bold">الإفراج عن المحتجزات</button>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                <h3 class="font-black text-slate-700 mb-2">التكاليف حسب النوع</h3>
                <div v-for="(label, key) in categoryLabel" :key="key" class="flex justify-between"><span>{{ label }}</span><b class="font-mono">{{ money(summary.costs_by_category[key] || 0) }}</b></div>
                <div class="flex justify-between border-t pt-1"><span>انحراف التكلفة (تقديري − فعلي)</span><b class="font-mono" :class="summary.cost_variance < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ money(summary.cost_variance) }}</b></div>
            </div>
        </div>

        <!-- BOQ -->
        <div v-if="tab === 'boq'" class="bg-white border border-slate-200 rounded-2xl p-4">
            <template v-if="!billings.length && $can('projects.boq.edit')">
                <BoqEditor v-model="boqForm.boq" />
                <p v-for="(msg, key) in boqForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
                <div class="flex justify-end mt-3"><button @click="saveBoq" :disabled="boqForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">حفظ جدول الكميات</button></div>
            </template>
            <div class="overflow-x-auto" v-else><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الرمز</th><th class="text-start px-3 py-2">الوصف</th><th class="px-3 py-2">الوحدة</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2">المنفذ</th><th class="px-3 py-2">%</th><th class="px-3 py-2 text-end">سعر الوحدة</th><th class="px-3 py-2 text-end">القيمة</th></tr></thead>
                <tbody>
                    <tr v-for="b in boq" :key="b.id" class="border-t border-slate-100" :class="b.is_group ? 'bg-slate-50 font-bold' : ''">
                        <td class="px-3 py-1.5 font-mono">{{ b.code }}</td><td class="px-3 py-1.5">{{ b.description }}</td>
                        <template v-if="!b.is_group">
                            <td class="px-3 py-1.5 text-center">{{ b.unit }}</td><td class="px-3 py-1.5 text-center font-mono">{{ qty(b.quantity) }}</td><td class="px-3 py-1.5 text-center font-mono">{{ qty(b.billed_quantity) }}</td>
                            <td class="px-3 py-1.5 text-center font-mono">{{ Number(b.quantity) ? Math.round(b.billed_quantity / b.quantity * 100) : 0 }}%</td>
                            <td class="px-3 py-1.5 text-end font-mono">{{ money(b.unit_price) }}</td><td class="px-3 py-1.5 text-end font-mono">{{ money(b.quantity * b.unit_price) }}</td>
                        </template>
                        <td v-else colspan="6"></td>
                    </tr>
                </tbody>
            </table></div>
        </div>

        <!-- Billings -->
        <div v-if="tab === 'billings'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">المستخلص</th><th class="px-3 py-2">التاريخ</th><th class="px-3 py-2 text-end">أعمال الفترة</th><th class="px-3 py-2 text-end">استرداد المقدم</th><th class="px-3 py-2 text-end">المحتجزات</th><th class="px-3 py-2 text-end">الضريبة</th><th class="px-3 py-2 text-end">الصافي المستحق</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2">الفاتورة</th></tr></thead>
                <tbody>
                    <tr v-for="b in billings" :key="b.id" class="border-t border-slate-100">
                        <td class="px-3 py-2"><Link :href="route('projects.billings.show', [project.id, b.id])" class="text-indigo-600 font-mono font-bold">{{ b.number }}</Link></td>
                        <td class="px-3 py-2 text-center font-mono">{{ date(b.billing_date) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.current_value) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.advance_recovery) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.retention) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(b.vat_amount) }}</td>
                        <td class="px-3 py-2 text-end font-mono font-bold">{{ money(b.net_payable) }}</td>
                        <td class="px-3 py-2 text-center"><StatusBadge :status="b.status" /></td>
                        <td class="px-3 py-2 text-center"><Link v-if="b.invoice" :href="route('sales.invoices.show', b.invoice.id)" class="text-indigo-600 font-mono">{{ b.invoice.invoice_number }}</Link></td>
                    </tr>
                    <tr v-if="!billings.length"><td colspan="9" class="py-8 text-center text-slate-400">لا توجد مستخلصات</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Variations -->
        <div v-if="tab === 'variations'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="p-3 border-b border-slate-100 text-end"><button v-if="$can('projects.variations.create')" @click="dialog = 'variation'" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold">أمر تغيير</button></div>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الرقم</th><th class="px-3 py-2">التاريخ</th><th class="text-start px-3 py-2">الوصف</th><th class="px-3 py-2 text-end">القيمة</th><th class="px-3 py-2 text-end">أثر التكلفة</th><th class="px-3 py-2">أيام</th><th class="px-3 py-2">الحالة</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="v in variations" :key="v.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ v.number }}</td><td class="px-3 py-2 text-center font-mono">{{ date(v.date) }}</td><td class="px-3 py-2">{{ v.description }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(v.amount) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(v.cost_impact) }}</td><td class="px-3 py-2 text-center">{{ v.time_impact_days }}</td>
                        <td class="px-3 py-2 text-center"><StatusBadge :status="v.status" /></td>
                        <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="v.status === 'draft' && $can('projects.variations.approve')"><button @click="variationAct(v, 'approve')" class="text-emerald-600 font-bold me-2">اعتماد</button><button @click="variationAct(v, 'reject')" class="text-rose-600 font-bold">رفض</button></template></td>
                    </tr>
                    <tr v-if="!variations.length"><td colspan="8" class="py-8 text-center text-slate-400">لا توجد أوامر تغيير</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Subcontracts -->
        <div v-if="tab === 'subcontracts'" class="space-y-3">
            <div class="text-end"><button v-if="$can('projects.subcontracts.create')" @click="dialog = 'subcontract'" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold">عقد باطن</button></div>
            <div v-for="s in subcontracts" :key="s.id" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <div class="flex flex-wrap justify-between gap-2">
                    <div><b class="font-mono">{{ s.number }}</b> — {{ s.supplier?.name }} <div class="text-slate-500">{{ s.scope }}</div></div>
                    <div class="flex gap-2 h-fit" v-if="$can('projects.subcontracts.approve')">
                        <button @click="openSub(s, 'subAdvance')" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">دفعة مقدمة</button>
                        <button @click="openSub(s, 'certify')" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">مستخلص</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mt-3">
                    <div>العقد: <b class="font-mono">{{ money(s.contract_value) }}</b></div>
                    <div>المعتمد: <b class="font-mono">{{ money(s.certified_to_date) }}</b></div>
                    <div>المقدم: <b class="font-mono">{{ money(s.advance_paid) }}</b> / مسترد <b class="font-mono">{{ money(s.advance_recovered) }}</b></div>
                    <div>المحتجزات: <b class="font-mono">{{ money(s.retention_held) }}</b> ({{ s.retention_percent }}%)</div>
                    <div>المستخلصات: <b>{{ s.certificates.length }}</b></div>
                </div>
                <div class="overflow-x-auto" v-if="s.certificates.length"><table class="w-full mt-3">
                    <tr v-for="c in s.certificates" :key="c.id" class="border-t border-slate-100"><td class="py-1 font-mono">{{ c.number }}</td><td class="font-mono">{{ date(c.date) }}</td><td class="text-end font-mono">{{ money(c.current_value) }}</td><td class="text-end font-mono">صافي {{ money(c.net_payable) }}</td></tr>
                </table></div>
            </div>
            <p v-if="!subcontracts.length" class="text-xs text-slate-400 text-center py-8">لا توجد عقود باطن</p>
        </div>

        <!-- Costs -->
        <div v-if="tab === 'costs'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="p-3 border-b border-slate-100 flex justify-end gap-2" v-if="$can('projects.costs.create')">
                <button @click="materialForm.direction = 'issue'; dialog = 'materials'" class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold">صرف مواد</button>
                <button @click="materialForm.direction = 'return'; dialog = 'materials'" class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold">مرتجع مواد</button>
                <button @click="dialog = 'cost'" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold">تكلفة مباشرة</button>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2">التاريخ</th><th class="px-3 py-2">النوع</th><th class="text-start px-3 py-2">البيان</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2 text-end">المبلغ</th></tr></thead>
                <tbody>
                    <tr v-for="c in costs" :key="c.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 text-center font-mono">{{ date(c.date) }}</td><td class="px-3 py-2 text-center">{{ categoryLabel[c.category] }}</td><td class="px-3 py-2">{{ c.description }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ Number(c.quantity) ? qty(c.quantity) : '' }}</td><td class="px-3 py-2 text-end font-mono" :class="c.amount < 0 ? 'text-rose-600' : ''">{{ money(c.amount) }}</td>
                    </tr>
                    <tr v-if="!costs.length"><td colspan="5" class="py-8 text-center text-slate-400">لا توجد تكاليف</td></tr>
                </tbody>
            </table></div>
        </div>

        <!-- Guarantees -->
        <div v-if="tab === 'guarantees'" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الرقم</th><th class="px-3 py-2">النوع</th><th class="px-3 py-2">البنك</th><th class="px-3 py-2 text-end">المبلغ</th><th class="px-3 py-2">الانتهاء</th><th class="px-3 py-2">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="g in guarantees" :key="g.id" class="border-t border-slate-100"><td class="px-3 py-2 font-mono">{{ g.number }}</td><td class="px-3 py-2 text-center">{{ g.type }}</td><td class="px-3 py-2 text-center">{{ g.bank_name }}</td><td class="px-3 py-2 text-end font-mono">{{ money(g.amount) }}</td><td class="px-3 py-2 text-center font-mono">{{ date(g.expiry_date) }}</td><td class="px-3 py-2 text-center">{{ g.status }}</td></tr>
                    <tr v-if="!guarantees.length"><td colspan="6" class="py-8 text-center text-slate-400">لا توجد خطابات ضمان — تُدار من شاشة <Link :href="route('projects.guarantees.index')" class="text-indigo-600">خطابات الضمان</Link></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Daily reports -->
        <div v-if="tab === 'daily'" class="grid md:grid-cols-3 gap-4">
            <div v-if="$can('projects.daily_reports.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                <Field label="التاريخ"><input v-model="dailyForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="grid grid-cols-2 gap-2">
                    <Field label="الطقس"><input v-model="dailyForm.weather" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="العمالة"><input v-model="dailyForm.manpower" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <Field label="المعدات"><input v-model="dailyForm.equipment" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الأعمال المنفذة" :error="dailyForm.errors.work_done"><textarea v-model="dailyForm.work_done" rows="3" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                <Field label="المعوقات"><textarea v-model="dailyForm.issues" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                <button @click="dailyForm.post(route('projects.daily-reports.store', project.id), { preserveScroll: true, onSuccess: () => dailyForm.reset('work_done', 'issues') })" class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">حفظ التقرير</button>
            </div>
            <div class="md:col-span-2 space-y-2">
                <div v-for="r in dailyReports" :key="r.id" class="bg-white border border-slate-200 rounded-2xl p-3 text-xs">
                    <div class="flex justify-between text-slate-500"><b class="font-mono text-slate-800">{{ date(r.date) }}</b><span>{{ r.weather }} · عمالة {{ r.manpower }} · {{ r.author?.name }}</span></div>
                    <p class="mt-1 whitespace-pre-line">{{ r.work_done }}</p>
                    <p v-if="r.issues" class="mt-1 text-rose-600 whitespace-pre-line">{{ r.issues }}</p>
                </div>
            </div>
        </div>

        <!-- Dialogs -->
        <Dialog :show="dialog === 'billing'" title="مستخلص جديد" size="xl" @close="close">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field label="تاريخ المستخلص"><input v-model="billingForm.billing_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الفترة من"><input v-model="billingForm.period_from" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="إلى"><input v-model="billingForm.period_to" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-xs">
                    <tr class="text-slate-500"><th class="text-start">البند</th><th>كمية العقد</th><th>المنفذ سابقاً</th><th class="w-28">كمية الفترة</th><th class="text-end">القيمة</th></tr>
                    <tr v-for="l in billingForm.lines" :key="l.boq_item_id" class="border-t border-slate-100">
                        <td class="py-1.5"><span class="font-mono">{{ l.code }}</span> {{ l.description }}</td>
                        <td class="text-center font-mono">{{ qty(l.contract) }}</td><td class="text-center font-mono">{{ qty(l.billed) }}</td>
                        <td><input v-model="l.current_quantity" type="number" min="0" :max="l.contract - l.billed" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                        <td class="text-end font-mono">{{ money(l.current_quantity * l.price) }}</td>
                    </tr>
                </table>
            </div>
            <p class="text-xs mt-2">قيمة أعمال الفترة: <b class="font-mono">{{ money(billingValue) }}</b> — يُخصم المقدم ({{ project.advance_percent }}%) والمحتجزات ({{ project.retention_percent }}%) عند الحفظ.</p>
            <p v-for="(msg, key) in billingForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <template #footer><button @click="saveBilling" :disabled="billingForm.processing || billingValue <= 0" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">حفظ كمسودة</button></template>
        </Dialog>

        <Dialog :show="dialog === 'advance'" title="دفعة مقدمة من العميل" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="التاريخ"><input v-model="advanceForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ قبل الضريبة" :error="advanceForm.errors.amount"><input v-model="advanceForm.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="طريقة الاستلام"><select v-model="advanceForm.payment_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">تحويل بنكي</option><option value="cheque">شيك</option><option value="cash">نقدي</option></select></Field>
                <Field label="الحساب"><select v-model="advanceForm.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <p class="text-[11px] text-slate-500 mt-2">تصدر فاتورة ضريبية بالدفعة (الضريبة مستحقة عند الاستلام) وتُسترد من المستخلصات بنسبة {{ project.advance_percent }}%.</p>
            <template #footer><button @click="advanceForm.post(route('projects.advance', project.id), opts)" :disabled="advanceForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">تسجيل</button></template>
        </Dialog>

        <Dialog :show="dialog === 'retention'" title="الإفراج عن المحتجزات" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="التاريخ"><input v-model="retentionForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ" :error="retentionForm.errors.amount"><input v-model="retentionForm.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="retentionForm.post(route('projects.retention.release', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إفراج</button></template>
        </Dialog>

        <Dialog :show="dialog === 'variation'" title="أمر تغيير" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="الوصف" :error="variationForm.errors.description" class="col-span-2"><input v-model="variationForm.description" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="التاريخ"><input v-model="variationForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="القيمة (سالبة للحذف)"><input v-model="variationForm.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="أثر التكلفة"><input v-model="variationForm.cost_impact" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="تمديد المدة (يوم)"><input v-model="variationForm.time_impact_days" type="number" min="0" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="variationForm.post(route('projects.variations.store', project.id), { ...opts, onSuccess: () => { close(); variationForm.reset(); } })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">حفظ</button></template>
        </Dialog>

        <Dialog :show="dialog === 'subcontract'" title="عقد مقاول باطن" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="المقاول (مورد)" :error="subForm.errors.supplier_id" class="col-span-2"><PartyPicker v-model="subForm.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field label="نطاق الأعمال" :error="subForm.errors.scope" class="col-span-2"><input v-model="subForm.scope" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="قيمة العقد"><input v-model="subForm.contract_value" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="المحتجزات %"><input v-model="subForm.retention_percent" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="subForm.post(route('projects.subcontracts.store', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">حفظ</button></template>
        </Dialog>

        <Dialog :show="dialog === 'subAdvance'" :title="`دفعة مقدمة - ${selectedSub?.number}`" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="التاريخ"><input v-model="subAdvanceForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ"><input v-model="subAdvanceForm.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="الحساب" class="col-span-2"><select v-model="subAdvanceForm.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <template #footer><button @click="subAdvanceForm.post(route('projects.subcontracts.advance', selectedSub.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">صرف</button></template>
        </Dialog>

        <Dialog :show="dialog === 'certify'" :title="`مستخلص مقاول باطن - ${selectedSub?.number}`" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="التاريخ"><input v-model="certifyForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="القيمة التراكمية للأعمال" :error="certifyForm.errors.cumulative_value"><input v-model="certifyForm.cumulative_value" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="نسبة الضريبة"><input v-model="certifyForm.vat_rate" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="رقم فاتورة المقاول"><input v-model="certifyForm.supplier_invoice_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <p class="text-xs mt-2">أعمال الفترة: <b class="font-mono">{{ money(certifyForm.cumulative_value - (selectedSub?.certified_to_date || 0)) }}</b></p>
            <template #footer><button @click="certifyForm.post(route('projects.subcontracts.certify', selectedSub.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">اعتماد</button></template>
        </Dialog>

        <Dialog :show="dialog === 'cost'" title="تكلفة مباشرة على المشروع" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="النوع"><select v-model="costForm.category" class="w-full rounded-xl border-slate-200 text-xs"><option value="labour">عمالة</option><option value="equipment">معدات</option><option value="other">أخرى</option></select></Field>
                <Field label="التاريخ"><input v-model="costForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="البيان" :error="costForm.errors.description" class="col-span-2"><input v-model="costForm.description" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ قبل الضريبة" :error="costForm.errors.amount"><input v-model="costForm.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="ضريبة المدخلات"><input v-model="costForm.vat_amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="السداد"><select v-model="costForm.paid_from" class="w-full rounded-xl border-slate-200 text-xs"><option value="accrued">مستحق (لاحقاً)</option><option value="payables">آجل لمورد</option><option value="cash">نقدي</option><option value="bank_transfer">بنكي</option></select></Field>
                <Field v-if="costForm.paid_from === 'payables'" label="المورد" :error="costForm.errors.supplier_id"><PartyPicker v-model="costForm.supplier_id" route-name="lookup.suppliers" /></Field>
                <Field v-if="['cash', 'bank_transfer'].includes(costForm.paid_from)" label="الحساب"><select v-model="costForm.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            </div>
            <template #footer><button @click="costForm.post(route('projects.costs.store', project.id), { ...opts, onSuccess: () => { close(); costForm.reset('description', 'amount', 'vat_amount'); } })" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">تسجيل</button></template>
        </Dialog>

        <Dialog :show="dialog === 'materials'" :title="materialForm.direction === 'issue' ? 'صرف مواد للمشروع' : 'مرتجع مواد من المشروع'" size="lg" @close="close">
            <div class="grid grid-cols-3 gap-3 mb-3">
                <Field label="التاريخ"><input v-model="materialForm.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المستودع"><select v-model="materialForm.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                <Field label="المرجع"><input v-model="materialForm.reference" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="relative mb-2">
                <input v-model="lookup" @input="searchProducts" placeholder="ابحث عن صنف..." class="w-full rounded-xl border-slate-200 text-xs" />
                <div v-if="results.length" class="absolute z-10 mt-1 w-full bg-white border rounded-xl shadow max-h-48 overflow-y-auto"><button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="addMaterial(p)" @keydown.enter.prevent="addMaterial(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">{{ p.name_ar }}</button></div>
            </div>
            <table class="w-full text-xs"><tr v-for="(l, i) in materialForm.lines" :key="i" class="border-t border-slate-100"><td class="py-1.5">{{ l.name }}</td><td class="w-32"><input v-model="l.quantity" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td><td class="w-8 text-center"><button @click="materialForm.lines.splice(i, 1)" class="text-rose-500">✕</button></td></tr></table>
            <p v-for="(msg, key) in materialForm.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <template #footer><button @click="materialForm.transform((d) => ({ ...d, lines: d.lines.map(({ product_id, quantity }) => ({ product_id, quantity })) })).post(route('projects.materials', project.id), { ...opts, onSuccess: () => { close(); materialForm.lines = []; } })" :disabled="!materialForm.lines.length" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">تنفيذ</button></template>
        </Dialog>

        <Dialog :show="dialog === 'edit'" title="بيانات المشروع" @close="close">
            <div class="grid grid-cols-2 gap-3">
                <Field label="الاسم" class="col-span-2"><input v-model="editForm.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الحالة"><select v-model="editForm.status" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(label, key) in statusLabel" :key="key" :value="key">{{ label }}</option></select></Field>
                <Field label="الموقع"><input v-model="editForm.location" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="البدء"><input v-model="editForm.start_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الانتهاء"><input v-model="editForm.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="استرداد المقدم %"><input v-model="editForm.advance_percent" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="المحتجزات %"><input v-model="editForm.retention_percent" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            </div>
            <template #footer><button @click="editForm.put(route('projects.update', project.id), opts)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

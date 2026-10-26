<script setup>
import { taxRate } from '@/utils/tax';
import { onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ rentals: Object, filters: Object, equipment: Array, projects: Array, prefillEquipment: Number });
const bases = { hourly: 'ساعة', daily: 'يوم', monthly: 'شهر' };
const today = new Date().toLocaleDateString('en-CA');
const blank = () => ({ type: 'customer', equipment_id: props.prefillEquipment || '', customer_id: null, project_id: '', billing_basis: 'hourly', rate: '', min_hours_per_day: '',
    with_operator: false, tax_rate: taxRate(), start_date: today, end_date: '', site: '', notes: '' });
const form = useForm(blank());
const open = ref(false);
const create = () => { form.defaults(blank()).reset(); form.clearErrors(); open.value = true; suggestRate(); };
const save = () => form.post(route('equipment.rentals.store'), { preserveScroll: true });

// Default price from the equipment card for the chosen basis.
const suggestRate = () => {
    const e = props.equipment.find((x) => x.id === Number(form.equipment_id));
    if (!e) return;
    form.rate = form.type === 'project'
        ? (form.billing_basis === 'hourly' ? e.internal_hourly_cost : '') || form.rate
        : { hourly: e.hourly_rate, daily: e.daily_rate, monthly: e.monthly_rate }[form.billing_basis] || form.rate;
};
watch(() => [form.equipment_id, form.billing_basis, form.type], suggestRate);

const q = ref(props.filters.q || '');
const filter = (extra = {}) => router.get(route('equipment.rentals.index'), { ...props.filters, q: q.value || undefined, ...extra }, { preserveState: true, replace: true });
onMounted(() => { if (props.prefillEquipment) create(); });
</script>

<template>
    <AppLayout>
        <Head title="عقود تأجير المعدات" />
        <PageHeader title="عقود تأجير المعدات" subtitle="تأجير للعملاء بفاتورة ضريبية، أو تحميل على مشاريع الشركة" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: 'عقود التأجير' }]">
            <template #actions>
                <button v-if="$can('equipment.rentals.create')" @click="create" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">+ عقد جديد</button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2 mb-3 text-xs">
            <select :value="filters.status || ''" @change="filter({ status: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="الحالة"><option value="">كل الحالات</option><option value="active">نشط</option><option value="closed">منتهٍ</option></select>
            <select :value="filters.type || ''" @change="filter({ type: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="النوع"><option value="">العملاء والمشاريع</option><option value="customer">تأجير لعملاء</option><option value="project">مشاريع داخلية</option></select>
            <form @submit.prevent="filter()"><input v-model="q" type="search" placeholder="رقم العقد، المعدة، العميل" class="rounded-xl border-slate-200 text-xs w-56" /></form>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">العقد</th><th class="px-3 py-2 text-start">المعدة</th><th class="px-3 py-2 text-start">الجهة</th><th class="px-3 py-2 text-start">السعر</th><th class="px-3 py-2 text-start">الفترة</th><th class="px-3 py-2 text-start">مفوتر حتى</th><th class="px-3 py-2 text-start">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="r in rentals.data" :key="r.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2"><Link :href="route('equipment.rentals.show', r.id)" class="text-indigo-700 font-mono font-bold">{{ r.number }}</Link></td>
                        <td class="px-3 py-2">{{ r.equipment?.code }} — {{ r.equipment?.name }}</td>
                        <td class="px-3 py-2"><span v-if="r.type === 'customer'">{{ r.customer?.name }}</span><span v-else class="text-sky-700">مشروع {{ r.project?.code }} — {{ r.project?.name }}</span></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ money(r.rate) }} / {{ bases[r.billing_basis] }}<span v-if="r.min_hours_per_day" class="text-slate-400"> (حد أدنى {{ Number(r.min_hours_per_day) }} س/يوم)</span></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ date(r.start_date) }} — {{ r.end_date ? date(r.end_date) : 'مفتوح' }}</td>
                        <td class="px-3 py-2">{{ r.billed_until ? date(r.billed_until) : '—' }}</td>
                        <td class="px-3 py-2"><span :class="['px-2 py-0.5 rounded-full font-bold', r.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500']">{{ r.status === 'active' ? 'نشط' : 'منتهٍ' }}</span></td>
                    </tr>
                    <tr v-if="!rentals.data.length"><td colspan="7" class="px-3 py-10 text-center text-slate-400">لا توجد عقود.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="rentals" />

        <Dialog :show="open" title="عقد تأجير جديد" size="lg" @close="open = false">
            <div class="space-y-3 text-xs">
                <div class="flex gap-2" role="radiogroup" aria-label="نوع العقد">
                    <button type="button" role="radio" :aria-checked="form.type === 'customer'" @click="form.type = 'customer'" :class="['flex-1 px-3 py-2 rounded-xl border font-bold', form.type === 'customer' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200']">تأجير لعميل (فاتورة)</button>
                    <button type="button" role="radio" :aria-checked="form.type === 'project'" @click="form.type = 'project'" :class="['flex-1 px-3 py-2 rounded-xl border font-bold', form.type === 'project' ? 'bg-sky-600 text-white border-sky-600' : 'border-slate-200']">تشغيل في مشروع للشركة</button>
                </div>
                <div class="grid md:grid-cols-2 gap-3">
                    <Field label="المعدة" :error="form.errors.equipment_id">
                        <select v-model="form.equipment_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">اختر</option><option v-for="e in equipment" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}{{ e.status !== 'available' ? ' (مشغولة)' : '' }}</option></select>
                    </Field>
                    <Field v-if="form.type === 'customer'" label="العميل" :error="form.errors.customer_id"><PartyPicker v-model="form.customer_id" /></Field>
                    <Field v-else label="المشروع" :error="form.errors.project_id">
                        <select v-model="form.project_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">اختر</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option></select>
                    </Field>
                    <Field label="أساس المحاسبة">
                        <select v-model="form.billing_basis" class="w-full rounded-xl border-slate-200 text-xs"><option value="hourly">بالساعة (من القراءات اليومية)</option><option value="daily">باليوم</option><option value="monthly">بالشهر</option></select>
                    </Field>
                    <Field :label="`السعر لكل ${bases[form.billing_basis]}`" :error="form.errors.rate"><input v-model="form.rate" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="form.billing_basis === 'hourly'" label="حد أدنى للساعات في اليوم" :error="form.errors.min_hours_per_day" hint="يُحتسب لكل يوم عمل حتى لو قلّت الساعات"><input v-model="form.min_hours_per_day" type="number" min="0" max="24" step="0.5" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field v-if="form.type === 'customer'" label="نسبة الضريبة %" :error="form.errors.tax_rate"><input v-model="form.tax_rate" type="number" min="0" max="100" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="تاريخ البداية" :error="form.errors.start_date"><input v-model="form.start_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="تاريخ النهاية (اختياري)" :error="form.errors.end_date"><input v-model="form.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="موقع العمل" class="md:col-span-2"><input v-model="form.site" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <label class="flex items-center gap-2 font-bold text-slate-700"><input v-model="form.with_operator" type="checkbox" class="rounded border-slate-300" /> مع مشغّل</label>
                <Field label="ملاحظات"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <template #footer>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">إنشاء العقد</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

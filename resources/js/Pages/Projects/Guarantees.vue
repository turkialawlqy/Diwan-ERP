<script setup>
import HijriDate from '@/Components/UI/HijriDate.vue';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money, date } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ guarantees: Object, totals: Array, projects: Array, filters: Object });
const types = { bid: 'ابتدائي', performance: 'نهائي (حسن تنفيذ)', advance_payment: 'دفعة مقدمة', retention: 'محتجزات' };
const statuses = { active: 'ساري', released: 'مفرج عنه', expired: 'منتهي', liquidated: 'مُسيّل' };
const open = ref(false);
const editing = ref(null);
const blank = { project_id: '', type: 'performance', number: '', bank_name: '', beneficiary: '', amount: 0, cash_margin: 0, issue_date: '', expiry_date: '', notes: '', status: 'active' };
const form = useForm({ ...blank });
const edit = (g) => { editing.value = g; Object.assign(form, { ...g, issue_date: g.issue_date?.substring(0, 10), expiry_date: g.expiry_date?.substring(0, 10), project_id: g.project_id || '' }); open.value = true; };
const create = () => { editing.value = null; Object.assign(form, blank); open.value = true; };
const save = () => editing.value
    ? form.put(route('projects.guarantees.update', editing.value.id), { onSuccess: () => (open.value = false) })
    : form.post(route('projects.guarantees.store'), { onSuccess: () => (open.value = false) });
const filter = (key, value) => router.get(route('projects.guarantees.index'), { ...props.filters, [key]: value || undefined }, { preserveState: true });
const daysLeft = (d) => Math.ceil((new Date(d) - new Date()) / 86400000);
</script>

<template>
    <AppLayout>
        <Head title="خطابات الضمان" />
        <PageHeader title="خطابات الضمان" subtitle="ابتدائية ونهائية ودفعات مقدمة ومحتجزات مع تنبيه قبل الانتهاء" :crumbs="[{ label: 'المقاولات' }, { label: 'خطابات الضمان' }]">
            <template #actions><button v-if="$can('projects.guarantees.create')" @click="create" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> خطاب ضمان</button></template>
        </PageHeader>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div v-for="t in totals" :key="t.type" class="bg-white border border-slate-200 rounded-2xl p-3 text-xs"><div class="text-slate-400">{{ types[t.type] }} (سارية)</div><b class="font-mono text-sm">{{ money(t.amount) }}</b><div class="text-slate-400">غطاء نقدي {{ money(t.margin) }}</div></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="flex gap-2 p-3 border-b border-slate-100 text-xs">
                <select :value="filters.status || ''" @change="filter('status', $event.target.value)" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                <select :value="filters.type || ''" @change="filter('type', $event.target.value)" class="rounded-xl border-slate-200 text-xs"><option value="">كل الأنواع</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الرقم</th><th class="px-3 py-2">النوع</th><th class="px-3 py-2">المشروع</th><th class="px-3 py-2">البنك</th><th class="px-3 py-2">المستفيد</th><th class="px-3 py-2 text-end">المبلغ</th><th class="px-3 py-2">الانتهاء</th><th class="px-3 py-2">الحالة</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="g in guarantees.data" :key="g.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-mono">{{ g.number }}</td><td class="px-3 py-2 text-center">{{ types[g.type] }}</td><td class="px-3 py-2 text-center font-mono">{{ g.project?.code || '—' }}</td>
                        <td class="px-3 py-2 text-center">{{ g.bank_name }}</td><td class="px-3 py-2 text-center">{{ g.beneficiary }}</td><td class="px-3 py-2 text-end font-mono">{{ money(g.amount) }}</td>
                        <td class="px-3 py-2 text-center" :class="g.status === 'active' && daysLeft(g.expiry_date) <= 30 ? 'text-rose-600 font-bold' : ''"><HijriDate :value="g.expiry_date" /></td>
                        <td class="px-3 py-2 text-center">{{ statuses[g.status] }}</td>
                        <td class="px-3 py-2 text-end"><button v-if="$can('projects.guarantees.edit')" @click="edit(g)" class="text-indigo-600 font-bold">تعديل</button></td>
                    </tr>
                    <tr v-if="!guarantees.data.length"><td colspan="9" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table></div>
            <Pagination :paginator="guarantees" />
        </div>
        <Dialog :show="open" :title="editing ? 'تعديل خطاب الضمان' : 'خطاب ضمان جديد'" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field label="النوع"><select v-model="form.type" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="المشروع"><select v-model="form.project_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} {{ p.name }}</option></select></Field>
                <Field label="رقم الخطاب" :error="form.errors.number"><input v-model="form.number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="البنك" :error="form.errors.bank_name"><input v-model="form.bank_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المستفيد" :error="form.errors.beneficiary" class="col-span-2"><input v-model="form.beneficiary" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المبلغ" :error="form.errors.amount"><input v-model="form.amount" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="الغطاء النقدي"><input v-model="form.cash_margin" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="تاريخ الإصدار" :error="form.errors.issue_date"><input v-model="form.issue_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تاريخ الانتهاء" :error="form.errors.expiry_date"><input v-model="form.expiry_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="editing" label="الحالة"><select v-model="form.status" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></Field>
            </div>
            <template #footer><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

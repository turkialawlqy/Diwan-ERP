<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date } from '@/utils/format';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ templates: Array, accounts: Array, costCenters: Array });
const open = ref(false);
const blank = () => ({ account_id: '', cost_center_id: '', debit: 0, credit: 0, description: '' });
const form = useForm({ name: '', description: '', frequency: 'monthly', next_run_date: new Date().toISOString().substring(0, 10), end_date: '', auto_reverse: false, lines: [blank(), blank()] });
const totals = computed(() => form.lines.reduce((t, l) => ({ d: t.d + Number(l.debit || 0), c: t.c + Number(l.credit || 0) }), { d: 0, c: 0 }));
const balanced = computed(() => Math.abs(totals.value.d - totals.value.c) < 0.01 && totals.value.d > 0);
const save = () => form.post(route('accounting.recurring.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
const toggle = (t) => router.put(route('accounting.recurring.update', t.id), { is_active: !t.is_active }, { preserveScroll: true });
const remove = (t) => confirm('حذف القيد المتكرر؟') && router.delete(route('accounting.recurring.destroy', t.id), { preserveScroll: true });
const freq = { monthly: 'شهري', quarterly: 'ربع سنوي', yearly: 'سنوي' };
</script>

<template>
    <AppLayout>
        <Head title="القيود المتكررة" />
        <PageHeader title="القيود المتكررة" subtitle="قيود تُرحّل آلياً بشكل دوري (إيجارات، استحقاقات، أقساط)" :crumbs="[{ label: 'المحاسبة' }, { label: 'القيود المتكررة' }]">
            <template #actions><button v-if="$can('accounting.recurring.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> قيد متكرر</button></template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('ui.name') }}</th><th class="px-4 py-2">التكرار</th><th class="px-4 py-2">التشغيل القادم</th><th class="text-end px-4 py-2">{{ $t('ui.amount') }}</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-semibold">{{ t.name }} <span v-if="t.auto_reverse" class="text-3xs text-indigo-600 font-bold">(يُعكس تلقائياً)</span><p class="text-slate-400 font-normal">{{ t.description }}</p><p v-if="t.last_error" class="text-rose-600 font-normal text-3xs">تعذّر الترحيل: {{ t.last_error }}</p></td>
                        <td class="px-4 py-2 text-center">{{ freq[t.frequency] }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(t.next_run_date) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ money(t.lines.reduce((s, l) => s + Number(l.debit || 0), 0)) }}</td>
                        <td class="px-4 py-2 text-center"><button v-if="$can('accounting.recurring.edit')" @click="toggle(t)" :class="t.is_active ? 'text-emerald-600' : 'text-slate-400'" class="font-bold">{{ t.is_active ? 'نشط' : 'موقوف' }}</button></td>
                        <td class="px-4 py-2 text-end"><button v-if="$can('accounting.recurring.delete')" @click="remove(t)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                    </tr>
                    <tr v-if="!templates.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
        </div>

        <Dialog :show="open" title="قيد متكرر جديد" size="lg" @close="open = false">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs mb-3">
                <Field label="الاسم" :error="form.errors.name"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="flex items-center gap-2 text-xs col-span-full"><input v-model="form.auto_reverse" type="checkbox" class="rounded" /> قيد استحقاق: يُعكس تلقائياً في اليوم التالي</label>
                <Field label="التكرار"><select v-model="form.frequency" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in freq" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="أول تشغيل"><input v-model="form.next_run_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="ينتهي في (اختياري)"><input v-model="form.end_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="col-span-2 md:col-span-4"><Field label="بيان القيد" :error="form.errors.description"><input v-model="form.description" class="w-full rounded-xl border-slate-200 text-xs" /></Field></div>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-xs">
                <tr v-for="(l, i) in form.lines" :key="i">
                    <td class="p-1"><select v-model="l.account_id" class="w-full rounded-lg border-slate-200 text-xs"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option></select></td>
                    <td class="p-1 w-36"><select v-model="l.cost_center_id" class="w-full rounded-lg border-slate-200 text-xs"><option value="">مركز تكلفة</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></td>
                    <td class="p-1 w-28"><input v-model="l.debit" @focus="l.credit = 0" type="number" step="0.01" placeholder="مدين" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="p-1 w-28"><input v-model="l.credit" @focus="l.debit = 0" type="number" step="0.01" placeholder="دائن" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    <td class="p-1 w-8"><button v-if="form.lines.length > 2" @click="form.lines.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td>
                </tr>
            </table></div>
            <div class="flex items-center justify-between mt-2 text-xs">
                <button @click="form.lines.push(blank())" class="text-indigo-600 font-bold">+ سطر</button>
                <span :class="balanced ? 'text-emerald-600' : 'text-rose-600'" class="font-mono font-bold">{{ money(totals.d) }} / {{ money(totals.c) }}</span>
            </div>
            <p v-if="form.errors.lines" class="text-xs text-rose-600 mt-2">{{ form.errors.lines }}</p>
            <template #footer><button @click="save" :disabled="form.processing || !balanced" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

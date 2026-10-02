<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { money, amount } from '@/utils/format';

const props = defineProps({ reconciliation: Object, lines: Array, previouslyCleared: Number, bookBalance: Number, account: Object });

const selected = ref(new Set(props.lines.filter((l) => l.reconciliation_id === props.reconciliation.id).map((l) => l.id)));
const locked = computed(() => props.reconciliation.status === 'completed');
const toggle = (id) => { if (locked.value) return; selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); selected.value = new Set(selected.value); };

const cleared = computed(() => props.previouslyCleared + props.lines.filter((l) => selected.value.has(l.id)).reduce((s, l) => s + l.debit - l.credit, 0));
const difference = computed(() => Math.round((Number(props.reconciliation.statement_balance) - cleared.value) * 100) / 100);

const form = useForm({ line_ids: [], complete: false });
const save = (complete) => {
    form.line_ids = [...selected.value];
    form.complete = complete;
    form.put(route('accounting.reconciliations.update', props.reconciliation.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head title="تسوية بنكية" />
        <PageHeader :title="`تسوية ${reconciliation.bank_account.name}`" :subtitle="`كشف البنك بتاريخ ${reconciliation.statement_date.substring(0, 10)} · الحساب ${account.code} - ${account.name_ar}`" :crumbs="[{ label: 'التسويات البنكية', href: route('accounting.reconciliations.index') }, { label: 'تسوية' }]">
            <template #actions v-if="!locked">
                <button @click="save(false)" :disabled="form.processing" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">حفظ</button>
                <button @click="save(true)" :disabled="form.processing || difference !== 0" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold disabled:opacity-40">إكمال التسوية</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="رصيد كشف البنك" :value="amount(reconciliation.statement_balance)" />
            <StatCard label="الرصيد الدفتري" :value="amount(bookBalance)" tone="indigo" />
            <StatCard label="الرصيد المسوّى" :value="amount(cleared)" tone="emerald" />
            <StatCard label="الفرق" :value="amount(difference)" :tone="difference === 0 ? 'emerald' : 'rose'" />
        </div>
        <p v-if="form.errors.line_ids" class="text-xs text-rose-600 mb-3">{{ form.errors.line_ids }}</p>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 w-10"></th><th class="px-3 py-2">{{ $t('ui.date') }}</th><th class="text-start px-3 py-2">القيد</th><th class="text-start px-3 py-2">{{ $t('ui.description') }}</th><th class="text-end px-3 py-2">إيداع</th><th class="text-end px-3 py-2">سحب</th></tr></thead>
                <tbody>
                    <tr v-for="l in lines" :key="l.id" @click="toggle(l.id)" :class="[selected.has(l.id) ? 'bg-emerald-50/60' : '', 'border-t border-slate-100 cursor-pointer']">
                        <td class="px-3 py-2 text-center"><input type="checkbox" :checked="selected.has(l.id)" :disabled="locked" class="rounded border-slate-300 text-emerald-600" @click.stop="toggle(l.id)" /></td>
                        <td class="px-3 py-2 text-center font-mono">{{ l.date }}</td>
                        <td class="px-3 py-2 font-mono text-slate-500">{{ l.entry_number }}</td>
                        <td class="px-3 py-2">{{ l.description }} <span v-if="l.reference" class="text-slate-400">· {{ l.reference }}</span></td>
                        <td class="px-3 py-2 text-end font-mono text-emerald-700">{{ l.debit ? money(l.debit) : '' }}</td>
                        <td class="px-3 py-2 text-end font-mono text-rose-700">{{ l.credit ? money(l.credit) : '' }}</td>
                    </tr>
                    <tr v-if="!lines.length"><td colspan="6" class="py-10 text-center text-slate-400">لا توجد حركات غير مسوّاة</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

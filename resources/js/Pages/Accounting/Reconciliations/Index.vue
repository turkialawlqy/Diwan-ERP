<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date } from '@/utils/format';

defineProps({ reconciliations: Object, bankAccounts: Array });
const form = useForm({ bank_account_id: '', statement_date: new Date().toISOString().substring(0, 10), statement_balance: '' });
const start = () => form.post(route('accounting.reconciliations.store'));
</script>

<template>
    <AppLayout>
        <Head title="التسويات البنكية" />
        <PageHeader title="التسويات البنكية" subtitle="مطابقة حركات البنك في الدفاتر مع كشف حساب البنك" :crumbs="[{ label: 'المحاسبة' }, { label: 'التسويات البنكية' }]" />

        <form v-if="$can('accounting.reconciliation.create')" @submit.prevent="start" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-4 gap-3 text-xs mb-4">
            <Field label="الحساب البنكي" :error="form.errors.bank_account_id"><select v-model="form.bank_account_id" required class="w-full rounded-xl border-slate-200 text-xs"><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            <Field label="تاريخ كشف البنك"><input v-model="form.statement_date" type="date" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="الرصيد حسب كشف البنك" :error="form.errors.statement_balance"><input v-model="form.statement_balance" type="number" step="0.01" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <div class="flex items-end"><button class="w-full rounded-xl bg-indigo-600 text-white font-bold py-2">بدء تسوية</button></div>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">الحساب</th><th class="px-4 py-2">تاريخ الكشف</th><th class="text-end px-4 py-2">رصيد الكشف</th><th class="text-end px-4 py-2">المسوّى</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="r in reconciliations.data" :key="r.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-semibold">{{ r.bank_account?.name }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(r.statement_date) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ money(r.statement_balance) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ money(r.reconciled_total) }}</td>
                        <td class="px-4 py-2 text-center"><span :class="r.status === 'completed' ? 'text-emerald-600' : 'text-amber-600'" class="font-bold">{{ r.status === 'completed' ? 'مكتملة' : 'قيد العمل' }}</span></td>
                        <td class="px-4 py-2 text-end"><Link :href="route('accounting.reconciliations.show', r.id)" class="text-indigo-600 font-bold">فتح</Link></td>
                    </tr>
                    <tr v-if="!reconciliations.data.length"><td colspan="6" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="reconciliations" />
        </div>
    </AppLayout>
</template>

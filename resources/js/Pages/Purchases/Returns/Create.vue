<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { money, qty } from '@/utils/format';

const props = defineProps({ invoice: Object, recentInvoices: Array, bankAccounts: Array, suggestedNumber: String });
const pick = (e) => router.get(route('purchases.returns.create'), { invoice_id: e.target.value });

const form = useForm({
    purchase_invoice_id: props.invoice?.id ?? null,
    return_date: new Date().toISOString().substring(0, 10),
    reason: '',
    settlement: 'credit',
    bank_account_id: '',
    items: (props.invoice?.items ?? []).map((i) => ({ purchase_invoice_item_id: i.id, name: i.item_name, returnable: i.returnable_quantity, unit_net: Number(i.quantity) ? (Number(i.quantity) * Number(i.unit_price) - Number(i.discount)) / Number(i.quantity) : 0, tax_rate: Number(i.tax_rate), quantity: 0 })),
});
const total = computed(() => form.items.reduce((s, i) => s + Number(i.quantity) * i.unit_net * (1 + i.tax_rate / 100), 0));
const save = () => form.transform((d) => ({ ...d, items: d.items.filter((i) => Number(i.quantity) > 0).map(({ purchase_invoice_item_id, quantity }) => ({ purchase_invoice_item_id, quantity })) }))
    .post(route('purchases.returns.store'));
</script>

<template>
    <AppLayout>
        <Head title="مرتجع مشتريات" />
        <PageHeader title="مرتجع مشتريات" :subtitle="`الرقم المتوقع: ${suggestedNumber}`" :crumbs="[{ label: 'المشتريات' }, { label: 'المرتجعات', href: route('purchases.returns.index') }, { label: 'جديد' }]" />
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <div class="grid md:grid-cols-4 gap-3">
                <Field label="فاتورة الشراء" class="md:col-span-2">
                    <select :value="invoice?.id" @change="pick" class="w-full rounded-xl border-slate-200 text-xs">
                        <option value="">اختر الفاتورة...</option>
                        <option v-for="inv in recentInvoices" :key="inv.id" :value="inv.id">{{ inv.invoice_number }} — {{ inv.supplier?.name }} ({{ money(inv.total) }})</option>
                    </select>
                </Field>
                <Field label="التاريخ"><input v-model="form.return_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="التسوية">
                    <select v-model="form.settlement" class="w-full rounded-xl border-slate-200 text-xs"><option value="credit">تخفيض رصيد المورد</option><option value="cash">استرداد نقدي</option><option value="bank_transfer">استرداد بنكي</option></select>
                </Field>
                <Field v-if="form.settlement !== 'credit'" label="الحساب"><select v-model="form.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <Field label="السبب" class="md:col-span-3"><input v-model="form.reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <div class="overflow-x-auto" v-if="invoice"><table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">القابل للإرجاع</th><th class="px-3 py-2 text-end">صافي سعر الوحدة</th><th class="px-3 py-2 w-32">كمية المرتجع</th></tr></thead>
                <tbody>
                    <tr v-for="i in form.items" :key="i.purchase_invoice_item_id" class="border-t border-slate-100">
                        <td class="px-3 py-2">{{ i.name }}</td>
                        <td class="px-3 py-2 text-center font-mono">{{ qty(i.returnable) }}</td>
                        <td class="px-3 py-2 text-end font-mono">{{ money(i.unit_net) }}</td>
                        <td class="px-3 py-2"><input v-model="i.quantity" type="number" min="0" :max="i.returnable" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs font-mono" /></td>
                    </tr>
                </tbody>
            </table></div>
            <p v-for="(msg, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ msg }}</p>
            <div class="flex justify-between items-center">
                <span class="text-xs">إجمالي المرتجع شامل الضريبة: <b class="font-mono">{{ money(total) }}</b></span>
                <button @click="save" :disabled="form.processing || !invoice || total <= 0" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">تسجيل المرتجع</button>
            </div>
        </div>
    </AppLayout>
</template>

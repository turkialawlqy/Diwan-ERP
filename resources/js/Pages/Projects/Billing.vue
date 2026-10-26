<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ project: Object, billing: Object, company: Object });
const approve = () => { if (confirm('اعتماد المستخلص وإصدار الفاتورة الضريبية؟ لا يمكن التعديل بعد الاعتماد.')) router.post(route('projects.billings.approve', [props.project.id, props.billing.id])); };
const remove = () => { if (confirm('حذف مسودة المستخلص؟')) router.delete(route('projects.billings.destroy', [props.project.id, props.billing.id])); };
const printPage = () => window.print();
</script>

<template>
    <AppLayout>
        <Head :title="`مستخلص ${billing.number}`" />
        <PageHeader :title="`مستخلص رقم ${billing.sequence} — ${billing.number}`" :subtitle="`${project.code} ${project.name}`" :crumbs="[{ label: 'المشاريع', href: route('projects.index') }, { label: project.code, href: route('projects.show', project.id) }, { label: billing.number }]">
            <template #actions>
                <StatusBadge :status="billing.status" />
                <template v-if="billing.status === 'draft'">
                    <button v-if="$can('projects.billings.approve')" @click="approve" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">اعتماد وإصدار الفاتورة</button>
                    <button v-if="$can('projects.billings.delete')" @click="remove" class="px-3 py-2 rounded-xl border border-rose-200 text-rose-600 text-xs font-bold">حذف</button>
                </template>
                <Link v-if="billing.invoice" :href="route('sales.invoices.show', billing.invoice.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">الفاتورة {{ billing.invoice.invoice_number }}</Link>
                <ShareDocument v-if="$can('projects.billings.print')" type="billing" :id="billing.id" />
                <button @click="printPage" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold">طباعة</button>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 text-xs space-y-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><div class="text-slate-400">المقاول</div><b>{{ company?.name }}</b> <span class="font-mono text-slate-400">{{ company?.tax_number }}</span></div>
                <div><div class="text-slate-400">المالك</div><b>{{ project.customer?.name }}</b></div>
                <div><div class="text-slate-400">رقم العقد</div><b>{{ project.contract_number || '—' }}</b></div>
                <div><div class="text-slate-400">الفترة</div><b class="font-mono">{{ date(billing.period_from) }} → {{ date(billing.period_to) }}</b></div>
            </div>
            <div class="overflow-x-auto"><table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-2 py-2">البند</th><th class="text-start px-2 py-2">الوصف</th><th class="px-2 py-2">الوحدة</th><th class="px-2 py-2">الكمية السابقة</th><th class="px-2 py-2">كمية الفترة</th><th class="px-2 py-2">التراكمية</th><th class="px-2 py-2 text-end">سعر الوحدة</th><th class="px-2 py-2 text-end">قيمة الفترة</th></tr></thead>
                <tbody>
                    <tr v-for="i in billing.items" :key="i.id" class="border-t border-slate-100">
                        <td class="px-2 py-1.5 font-mono">{{ i.boq_item?.code }}</td><td class="px-2 py-1.5">{{ i.boq_item?.description }}</td><td class="px-2 py-1.5 text-center">{{ i.boq_item?.unit }}</td>
                        <td class="px-2 py-1.5 text-center font-mono">{{ qty(i.previous_quantity) }}</td><td class="px-2 py-1.5 text-center font-mono">{{ qty(i.current_quantity) }}</td>
                        <td class="px-2 py-1.5 text-center font-mono">{{ qty(Number(i.previous_quantity) + Number(i.current_quantity)) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono">{{ money(i.unit_price) }}</td><td class="px-2 py-1.5 text-end font-mono">{{ money(i.amount) }}</td>
                    </tr>
                </tbody>
            </table></div>
            <div class="flex justify-end">
                <dl class="w-80 space-y-1">
                    <div class="flex justify-between"><dt>إجمالي الأعمال التراكمية</dt><dd class="font-mono">{{ money(billing.cumulative_value) }}</dd></div>
                    <div class="flex justify-between"><dt>يخصم: المستخلصات السابقة</dt><dd class="font-mono">({{ money(billing.previous_value) }})</dd></div>
                    <div class="flex justify-between font-bold"><dt>أعمال هذه الفترة</dt><dd class="font-mono">{{ money(billing.current_value) }}</dd></div>
                    <div class="flex justify-between"><dt>يخصم: استرداد الدفعة المقدمة</dt><dd class="font-mono">({{ money(billing.advance_recovery) }})</dd></div>
                    <div class="flex justify-between"><dt>المبلغ الخاضع للضريبة</dt><dd class="font-mono">{{ money(billing.taxable_amount) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ $taxLabel() }}</dt><dd class="font-mono">{{ money(billing.vat_amount) }}</dd></div>
                    <div class="flex justify-between"><dt>يخصم: المحتجزات</dt><dd class="font-mono">({{ money(billing.retention) }})</dd></div>
                    <div class="flex justify-between border-t pt-1 font-black text-sm"><dt>الصافي المستحق</dt><dd class="font-mono">{{ money(billing.net_payable) }}</dd></div>
                </dl>
            </div>
            <p v-if="billing.invoice" class="text-slate-500">الفاتورة الضريبية {{ billing.invoice.invoice_number }} — حالة الفوترة الإلكترونية: {{ billing.invoice.zatca_status }}</p>
        </div>
    </AppLayout>
</template>

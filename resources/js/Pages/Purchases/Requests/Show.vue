<script setup>
import { ask } from '@/composables/useDialog';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import ApprovalTimeline from '@/Components/Documents/ApprovalTimeline.vue';
import { money, date, qty } from '@/utils/format';

const props = defineProps({ request: Object, approvals: Array, orders: Array, warehouses: Array });
const act = async (name) => {
    let comment = '';
    if (name.endsWith('reject')) {
        comment = await ask('سبب رفض الطلب:', { title: 'رفض الطلب', type: 'textarea', confirmText: 'رفض', danger: true });
        if (comment === null) return;
    }
    router.post(route(name, props.request.id), { comment });
};
const open = ref(false);
const form = useForm({ supplier_id: null, warehouse_id: '' });
const convert = () => form.post(route('purchases.requests.convert', props.request.id));
</script>

<template>
    <AppLayout>
        <Head :title="`طلب شراء ${request.request_number}`" />
        <PageHeader :title="`طلب شراء ${request.request_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'طلبات الشراء', href: route('purchases.requests.index') }, { label: request.request_number }]">
            <template #actions>
                <StatusBadge :status="request.status" />
                <template v-if="request.status === 'pending_approval' && $can('purchases.requests.approve')">
                    <button @click="act('purchases.requests.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">اعتماد</button>
                    <button @click="act('purchases.requests.reject')" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">رفض</button>
                </template>
                <button v-if="request.status === 'approved' && $can('purchases.requests.convert')" @click="open = true" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">تحويل لأمر شراء</button>
            </template>
        </PageHeader>
        <div class="grid lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="p-4 grid grid-cols-3 gap-3 text-xs">
                    <div><div class="text-slate-400">التاريخ</div><b class="font-mono">{{ date(request.request_date) }}</b></div>
                    <div><div class="text-slate-400">مطلوب بتاريخ</div><b class="font-mono">{{ date(request.required_date) }}</b></div>
                    <div><div class="text-slate-400">مقدم الطلب</div><b>{{ request.requester?.name }}</b></div>
                </div>
                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الصنف</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2 text-end">السعر التقديري</th></tr></thead>
                    <tbody><tr v-for="i in request.items" :key="i.id" class="border-t border-slate-100"><td class="px-3 py-2">{{ i.item_name }}</td><td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(i.estimated_price) }}</td></tr></tbody>
                </table></div>
                <p v-if="request.notes" class="p-3 text-xs text-slate-500">{{ request.notes }}</p>
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <h3 class="font-black text-slate-700 mb-2">أوامر الشراء</h3>
                    <div v-for="o in orders" :key="o.id" class="flex justify-between py-1"><Link :href="route('purchases.orders.show', o.id)" class="text-indigo-600 font-mono">{{ o.order_number }}</Link><StatusBadge :status="o.status" /></div>
                    <p v-if="!orders.length" class="text-slate-400">لا يوجد</p>
                </div>
                <ApprovalTimeline :approvals="approvals" />
            </div>
        </div>
        <Dialog :show="open" title="تحويل إلى أمر شراء" @close="open = false">
            <Field label="المورد" :error="form.errors.supplier_id"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" /></Field>
            <Field label="المستودع" class="mt-3"><select v-model="form.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
            <p v-if="form.errors.request" class="text-xs text-rose-600 mt-2">{{ form.errors.request }}</p>
            <template #footer><button @click="convert" :disabled="form.processing || !form.supplier_id" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إنشاء مسودة أمر شراء</button></template>
        </Dialog>
    </AppLayout>
</template>

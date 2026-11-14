<script setup>
import { useI18n } from 'vue-i18n';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
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
const { t } = useI18n();

const props = defineProps({ request: Object, approvals: Array, orders: Array, warehouses: Array });
const act = async (name) => {
    let comment = '';
    if (name.endsWith('reject')) {
        comment = await ask(t('p_requests_show.reject_q'), { title: t('p_requests_show.reject_title'), type: 'textarea', confirmText: t('p_requests_show.reject'), danger: true });
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
        <Head :title="`${$t('p_requests_index.purchase_request')} ${request.request_number}`" />
        <PageHeader :title="`${$t('p_requests_index.purchase_request')} ${request.request_number}`" :crumbs="[{ label: 'المشتريات' }, { label: 'طلبات الشراء', href: route('purchases.requests.index') }, { label: request.request_number }]">
            <template #actions>
                <StatusBadge :status="request.status" />
                <template v-if="request.status === 'pending_approval' && $can('purchases.requests.approve')">
                    <button @click="act('purchases.requests.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">{{ $t('p_requests_show.approve') }}</button>
                    <button @click="act('purchases.requests.reject')" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">{{ $t('p_requests_show.reject') }}</button>
                </template>
                <button v-if="request.status === 'approved' && $can('purchases.requests.convert')" @click="open = true" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('p_requests_show.convert_to_a_purchase_order') }}</button>
            </template>
        </PageHeader>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="p-4 grid grid-cols-3 gap-3 text-xs">
                    <div><div class="text-slate-400">{{ $t('p_requests_show.date') }}</div><b class="font-mono">{{ date(request.request_date) }}</b></div>
                    <div><div class="text-slate-400">{{ $t('p_requests_show.required_by') }}</div><b class="font-mono">{{ date(request.required_date) }}</b></div>
                    <div><div class="text-slate-400">{{ $t('p_requests_show.requester') }}</div><b>{{ request.requester?.name }}</b></div>
                </div>
                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('p_requests_show.item') }}</th><th class="px-3 py-2">{{ $t('p_requests_show.quantity') }}</th><th class="px-3 py-2 text-end">{{ $t('p_requests_show.estimated_price') }}</th></tr></thead>
                    <tbody><tr v-for="i in request.items" :key="i.id" class="border-t border-slate-100"><td class="px-3 py-2">{{ i.item_name }}</td><td class="px-3 py-2 text-center font-mono">{{ qty(i.quantity) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(i.estimated_price) }}</td></tr></tbody>
                </table></div>
                <p v-if="request.notes" class="p-3 text-xs text-slate-500">{{ request.notes }}</p>
            </div>
            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <h3 class="font-black text-slate-700 mb-2">{{ $t('p_requests_show.purchase_orders') }}</h3>
                    <div v-for="o in orders" :key="o.id" class="flex justify-between py-1"><Link :href="route('purchases.orders.show', o.id)" class="text-indigo-600 font-mono">{{ o.order_number }}</Link><StatusBadge :status="o.status" /></div>
                    <p v-if="!orders.length" class="text-slate-400">{{ $t('p_requests_show.none') }}</p>
                </div>
                <ApprovalTimeline :approvals="approvals" />
            </div>
        </div>
        <Dialog :show="open" :title="$t('p_requests_show.convert_to_a_purchase_order_2')" @close="open = false">
            <Field :label="$t('p_requests_show.supplier')" :error="form.errors.supplier_id"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" /></Field>
            <Field :label="$t('p_requests_show.warehouse')" class="mt-3"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :placeholder="$t('p_requests_show.default')" /></Field>
            <p v-if="form.errors.request" class="text-xs text-rose-600 mt-2">{{ form.errors.request }}</p>
            <template #footer><button @click="convert" :disabled="form.processing || !form.supplier_id" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('p_requests_show.create_a_draft_purchase_order') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

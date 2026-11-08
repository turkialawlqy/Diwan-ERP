<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import ApprovalTimeline from '@/Components/Documents/ApprovalTimeline.vue';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';
import { ask, confirmAction } from '@/composables/useDialog';
import { money, date } from '@/utils/format';

const props = defineProps({ paymentRequest: Object, approvals: Array, executeUrl: { type: String, default: null } });
const { t } = useI18n();
const r = computed(() => props.paymentRequest);
const payee = computed(() => r.value.supplier?.name || r.value.employee?.name || r.value.payee_name || '—');
const post = (name, data = {}) => router.post(route(name, r.value.id), data, { preserveScroll: true });
const reject = async () => {
    const comment = await ask(t('prq.reject_reason'), { title: t('prq.reject'), type: 'textarea', danger: true });
    if (comment !== null) post('finance.payment-requests.reject', { comment });
};
const cancel = async () => { if (await confirmAction(t('prq.confirm_cancel'), { title: t('prq.cancel'), danger: true })) post('finance.payment-requests.cancel'); };
const editable = computed(() => ['draft', 'rejected'].includes(r.value.status));
</script>

<template>
    <AppLayout>
        <Head :title="`${$t('prq.title')} ${r.request_number}`" />
        <PageHeader :title="`${$t('prq.title')} ${r.request_number}`" :crumbs="[{ label: $t('nav.finance') }, { label: $t('prq.title'), href: route('finance.payment-requests.index') }, { label: r.request_number }]">
            <template #actions>
                <StatusBadge :status="r.status" />
                <Link v-if="editable && $can('finance.payment_requests.edit')" :href="route('finance.payment-requests.edit', r.id)" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('ui.edit') }}</Link>
                <button v-if="editable && $can('finance.payment_requests.create')" type="button" @click="post('finance.payment-requests.submit')" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-prq-submit>{{ $t('prq.submit') }}</button>
                <template v-if="r.status === 'pending_approval' && $can('finance.payment_requests.approve')">
                    <button type="button" @click="post('finance.payment-requests.approve')" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" data-prq-approve>{{ $t('prq.approve') }}</button>
                    <button type="button" @click="reject" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">{{ $t('prq.reject') }}</button>
                </template>
                <a v-if="executeUrl" :href="executeUrl" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" data-prq-execute>{{ $t('prq.execute') }}</a>
                <button v-if="['draft', 'pending_approval', 'approved', 'rejected'].includes(r.status) && $can('finance.payment_requests.edit')" type="button" @click="cancel" class="px-3 py-2 rounded-xl border border-rose-200 text-rose-700 text-xs font-bold">{{ $t('prq.cancel') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                <div><div class="text-slate-400">{{ $t('prq.date') }}</div><b class="font-mono">{{ date(r.request_date) }}</b></div>
                <div><div class="text-slate-400">{{ $t('prq.due') }}</div><b class="font-mono">{{ r.due_date ? date(r.due_date) : '—' }}</b></div>
                <div><div class="text-slate-400">{{ $t('prq.requester') }}</div><b>{{ r.requester?.name }}</b></div>
                <div><div class="text-slate-400">{{ $t('prq.kind') }}</div><b>{{ r.kind === 'expense' ? $t('prq.k_expense') : $t('prq.k_supplier_payment') }}</b></div>
                <div><div class="text-slate-400">{{ $t('prq.payee') }}</div><b>{{ payee }}</b></div>
                <div><div class="text-slate-400">{{ $t('prq.amount') }}</div><b class="font-mono">{{ money(r.amount) }}</b></div>
                <div class="col-span-2 md:col-span-3"><div class="text-slate-400">{{ $t('prq.purpose') }}</div><b>{{ r.purpose }}</b></div>
                <div v-if="r.account"><div class="text-slate-400">{{ $t('prq.account') }}</div><b>{{ r.account.code }} - {{ r.account.name_ar }}</b></div>
                <div v-if="r.notes" class="col-span-2 md:col-span-3 text-slate-500">{{ r.notes }}</div>
                <div v-if="r.paid_id" class="col-span-2 md:col-span-3 text-emerald-700 font-bold">{{ $t('prq.paid_with') }} #{{ r.paid_id }}</div>
            </div>
            <div class="space-y-4">
                <ApprovalTimeline :approvals="approvals" />
                <AttachmentsPanel kind="payment-request" :id="r.id" />
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import ShareDocument from '@/Components/Documents/ShareDocument.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money } from '@/utils/format';

const props = defineProps({ contract: Object, renewals: Array, project: Object, subscription: Object, approvals: Array, acceptances: { type: Array, default: () => [] }, canApprove: Boolean, statuses: Object, kinds: Object, obligationKinds: Object });
const c = computed(() => props.contract);
const post = (name, data = {}) => router.post(route(name, c.value.id), data, { preserveScroll: true });
const share = (shared, requires) => router.post(route('contracts.share', c.value.id), { shared_with_customer: shared, requires_acceptance: requires }, { preserveScroll: true });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
const tone = { draft: 'bg-slate-100 text-slate-600 border-slate-200', pending_approval: 'bg-amber-50 text-amber-700 border-amber-200', rejected: 'bg-rose-50 text-rose-700 border-rose-200', approved: 'bg-sky-50 text-sky-700 border-sky-200', active: 'bg-emerald-50 text-emerald-700 border-emerald-200', expired: 'bg-orange-50 text-orange-700 border-orange-200', terminated: 'bg-rose-50 text-rose-700 border-rose-200', renewed: 'bg-violet-50 text-violet-700 border-violet-200' };
const editable = computed(() => ['draft', 'rejected'].includes(c.value.status));
const running = computed(() => ['approved', 'active'].includes(c.value.status));
const overdue = (o) => o.status === 'pending' && new Date(o.due_date) < new Date(new Date().toDateString());
const btn = 'px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50';

const decide = ref(null); // 'approve' | 'reject'
const decision = useForm({ comment: '' });
const send = () => decision.post(route(decide.value === 'approve' ? 'contracts.approve' : 'contracts.reject', c.value.id), { preserveScroll: true, onSuccess: () => { decide.value = null; decision.reset(); } });

const terminating = ref(false);
const terminate = useForm({ reason: '' });
const doTerminate = () => terminate.post(route('contracts.terminate', c.value.id), { preserveScroll: true, onSuccess: () => (terminating.value = false) });

const amending = ref(false);
const amend = useForm({ body: '', note: '' });
const openAmend = () => { amend.body = c.value.body || ''; amend.note = ''; amending.value = true; };
const doAmend = () => amend.post(route('contracts.amend', c.value.id), { preserveScroll: true, onSuccess: () => (amending.value = false) });

const viewing = ref(null);
const toggle = (o) => router.post(route('contracts.obligations.toggle', o.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('con2_contracts_show.contract_n', { n0: c.number })" />
        <PageHeader :title="`${c.number} — ${c.title}`" :subtitle="$t('con2_contracts_show.version_n', { n0: c.version })" :crumbs="[{ label: $t('con2_contracts_show.contracts'), href: route('contracts.index') }, { label: c.number }]">
            <template #actions>
                <ShareDocument v-if="$can('contracts.contracts.view')" type="contract" :id="contract.id" />
                <template v-if="$can('contracts.contracts.edit')">
                    <Link v-if="editable" :href="route('contracts.edit', c.id)" :class="btn" data-edit>{{ $t('con2_contracts_show.edit') }}</Link>
                    <button v-if="editable" type="button" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-bold" @click="post('contracts.submit')" data-submit>{{ $t('con2_contracts_show.send_for_approval') }}</button>
                    <button v-if="c.status === 'approved'" type="button" :class="btn" @click="post('contracts.activate')" data-activate>{{ $t('con2_contracts_show.activate') }}</button>
                    <button v-if="running" type="button" :class="btn" @click="openAmend" data-amend>{{ $t('con2_contracts_show.new_text_version') }}</button>
                    <button v-if="['active', 'expired'].includes(c.status)" type="button" :class="btn" @click="post('contracts.renew')" data-renew>{{ $t('con2_contracts_show.renew') }}</button>
                    <button v-if="running" type="button" class="px-3 py-1.5 rounded-lg border border-rose-200 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="terminating = true" data-terminate>{{ $t('con2_contracts_show.terminate') }}</button>
                </template>
                <template v-if="canApprove">
                    <button type="button" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold" @click="decide = 'approve'" data-approve>{{ $t('con2_contracts_show.approve') }}</button>
                    <button type="button" class="px-3 py-1.5 rounded-lg border border-rose-200 text-xs font-bold text-rose-700" @click="decide = 'reject'" data-reject>{{ $t('con2_contracts_show.reject') }}</button>
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <h2 class="font-bold text-slate-800 mb-2">{{ $t('con2_contracts_show.contract_text') }}</h2>
                    <p v-if="c.body" class="whitespace-pre-line leading-relaxed" data-contract-body>{{ c.body }}</p>
                    <p v-else class="text-slate-400">{{ $t('con2_contracts_show.no_text_recorded') }}</p>
                </div>

                <div v-if="c.party_type === 'customer' && $canRoute('contracts.share')" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2" data-portal-share>
                    <h2 class="font-bold text-slate-800">{{ $t('con2_contracts_show.customer_portal') }}</h2>
                    <label class="flex items-center gap-2"><input type="checkbox" :checked="c.shared_with_customer" class="rounded border-slate-300" @change="share($event.target.checked, c.requires_acceptance)" data-share-toggle /> {{ $t('con2_contracts_show.share_the_contract_with_the_cust') }}</label>
                    <label v-if="c.shared_with_customer" class="flex items-center gap-2"><input type="checkbox" :checked="c.requires_acceptance" class="rounded border-slate-300" @change="share(true, $event.target.checked)" data-require-acceptance /> {{ $t('con2_contracts_show.requires_the_customer_s_acceptan') }}</label>
                    <p v-for="(a, i) in acceptances" :key="i" class="text-emerald-700 font-bold" data-acceptance>{{ $t('con2_contracts_show.n_accepted_version_n', { n0: a.name, n1: a.version }) }} <span class="font-mono">{{ String(a.accepted_at).slice(0, 16).replace('T', ' ') }}</span></p>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                    <h2 class="px-5 py-3 font-bold text-slate-800 border-b border-slate-100">{{ $t('con2_contracts_show.obligations') }}</h2>
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('con2_contracts_show.obligation') }}</th><th class="px-4 py-2 text-start">{{ $t('con2_contracts_show.type') }}</th><th class="px-4 py-2 text-start">{{ $t('con2_contracts_show.due') }}</th><th class="px-4 py-2 text-start">{{ $t('con2_contracts_show.amount') }}</th><th class="px-4 py-2 text-start">{{ $t('con2_contracts_show.status') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="o in c.obligations" :key="o.id" class="border-t border-slate-100" :data-obligation="o.title">
                                <td class="px-4 py-2 font-bold">{{ o.title }}</td><td class="px-4 py-2">{{ obligationKinds[o.kind] }}</td>
                                <td class="px-4 py-2 font-mono" :class="overdue(o) ? 'text-rose-600 font-bold' : ''">{{ d(o.due_date) }}<span v-if="overdue(o)" class="block text-3xs">{{ $t('con2_contracts_show.overdue') }}</span></td>
                                <td class="px-4 py-2 font-mono">{{ o.amount != null ? money(o.amount) : '—' }}</td>
                                <td class="px-4 py-2">
                                    <button v-if="$can('contracts.contracts.edit') && running" type="button" class="px-2 py-0.5 rounded-full border font-bold" :class="o.status === 'done' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'border-slate-200 text-slate-600'" @click="toggle(o)" data-toggle-obligation>{{ o.status === 'done' ? $t('con2_contracts_show.done') : $t('con2_contracts_show.mark_as_done') }}</button>
                                    <span v-else>{{ o.status === 'done' ? $t('con2_contracts_show.done') : $t('con2_contracts_show.pending') }}</span>
                                </td>
                            </tr>
                            <tr v-if="!c.obligations.length"><td colspan="5" class="py-6 text-center text-slate-400">{{ $t('con2_contracts_show.no_obligations') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto" data-versions>
                    <h2 class="px-5 py-3 font-bold text-slate-800 border-b border-slate-100">{{ $t('con2_contracts_show.versions') }}</h2>
                    <table class="w-full">
                        <tbody>
                            <tr v-for="v in c.versions" :key="v.id" class="border-t border-slate-100">
                                <td class="px-4 py-2 font-mono font-bold">v{{ v.version }}</td><td class="px-4 py-2">{{ v.note }}</td><td class="px-4 py-2 text-slate-500">{{ v.author?.name }}</td>
                                <td class="px-4 py-2 font-mono text-slate-500">{{ d(v.created_at) }}</td>
                                <td class="px-4 py-2 text-end"><button type="button" class="text-indigo-600 font-bold" @click="viewing = v">{{ $t('con2_contracts_show.view') }}</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                    <div class="flex items-center justify-between"><span class="text-slate-500">{{ $t('con2_contracts_show.status') }}</span><span class="px-2 py-0.5 rounded-full border font-bold" :class="tone[c.status]" data-status>{{ statuses[c.status] }}</span></div>
                    <p><span class="text-slate-500">{{ $t('con2_contracts_show.party') }} </span><b>{{ c.party_type === 'supplier' ? c.supplier?.name : c.customer?.name }}</b> <span class="text-slate-400">({{ c.party_type === 'supplier' ? $t('con2_contracts_show.supplier') : $t('con2_contracts_show.customers') }})</span></p>
                    <p><span class="text-slate-500">{{ $t('con2_contracts_show.type_2') }} </span>{{ kinds[c.kind] }}</p>
                    <p><span class="text-slate-500">{{ $t('con2_contracts_show.value') }} </span><span class="font-mono font-bold">{{ money(c.value) }}</span></p>
                    <p class="font-mono"><span class="text-slate-500 font-sans">{{ $t('con2_contracts_show.term') }} </span>{{ d(c.start_date) }} → {{ c.end_date ? d(c.end_date) : $t('con2_contracts_show.open') }}</p>
                    <p v-if="c.payment_terms"><span class="text-slate-500">{{ $t('con2_contracts_show.payment') }} </span>{{ c.payment_terms }}</p>
                    <p><span class="text-slate-500">{{ $t('con2_contracts_show.renewal') }} </span>{{ $t('con2_contracts_show.n_alert_n_days_before', { n0: c.auto_renew ? `تلقائي كل ${c.renewal_term_months} شهر` : 'يدوي', n1: c.renewal_notice_days }) }}</p>
                    <p v-if="c.terminated_at" class="text-rose-600"><b>{{ $t('con2_contracts_show.terminated_on_n', { n0: d(c.terminated_at) }) }}</b>: {{ c.termination_reason }}</p>
                    <p v-if="c.parent"><span class="text-slate-500">{{ $t('con2_contracts_show.renewal_of_contract') }} </span><Link :href="route('contracts.show', c.parent.id)" class="text-indigo-600 font-bold font-mono">{{ c.parent.number }}</Link></p>
                    <p v-for="r in renewals" :key="r.id"><span class="text-slate-500">{{ $t('con2_contracts_show.renewed_by_contract') }} </span><Link :href="route('contracts.show', r.id)" class="text-indigo-600 font-bold font-mono">{{ r.number }}</Link></p>
                    <p v-if="project"><span class="text-slate-500">{{ $t('con2_contracts_show.project') }} </span><Link :href="route('projects.show', project.id)" class="text-indigo-600 font-bold">{{ project.code }}</Link></p>
                    <p v-if="subscription"><span class="text-slate-500">{{ $t('con2_contracts_show.subscription') }} </span><Link :href="route('sales.subscriptions.show', subscription.id)" class="text-indigo-600 font-bold font-mono">{{ subscription.number }}</Link></p>
                </div>

                <div v-if="c.attachments?.length" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1">
                    <h3 class="font-bold text-slate-800">{{ $t('con2_contracts_show.attachments') }}</h3>
                    <a v-for="(a, i) in c.attachments" :key="i" :href="a.url" target="_blank" class="block text-indigo-600 font-bold">{{ a.name }}</a>
                </div>

                <div v-if="approvals.length" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5" data-approvals>
                    <h3 class="font-bold text-slate-800">{{ $t('con2_contracts_show.approval_log') }}</h3>
                    <p v-for="a in approvals" :key="a.id" class="text-slate-600"><b>{{ a.user?.name }}</b> · {{ { submitted: $t('con2_contracts_show.send_for_approval_2'), approved: $t('con2_contracts_show.approve_2'), rejected: $t('con2_contracts_show.reject') }[a.action] || a.action }}<span v-if="a.comment" class="block text-slate-400">{{ a.comment }}</span></p>
                </div>
            </div>
        </div>

        <Dialog :show="!!decide" :title="decide === 'approve' ? $t('con2_contracts_show.approve_the_contract') : $t('con2_contracts_show.reject_the_contract')" @close="decide = null">
            <Field :label="$t('con2_contracts_show.note_optional')"><input v-model="decision.comment" maxlength="500" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="decide = null">{{ $t('con2_contracts_show.back') }}</button><button type="button" :disabled="decision.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" @click="send" data-confirm-decision>{{ $t('con2_contracts_show.confirm') }}</button></template>
        </Dialog>

        <Dialog :show="terminating" :title="$t('con2_contracts_show.terminate_the_contract')" @close="terminating = false">
            <Field :label="$t('con2_contracts_show.termination_reason')" :error="terminate.errors.reason"><input v-model="terminate.reason" maxlength="500" class="w-full rounded-xl border-slate-200 text-xs" data-terminate-reason /></Field>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="terminating = false">{{ $t('con2_contracts_show.back') }}</button><button type="button" :disabled="terminate.processing || !terminate.reason" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold disabled:opacity-50" @click="doTerminate" data-confirm-terminate>{{ $t('con2_contracts_show.confirm_termination') }}</button></template>
        </Dialog>

        <Dialog :show="amending" :title="$t('con2_contracts_show.new_version_of_the_contract_text')" size="lg" @close="amending = false">
            <div class="space-y-3 text-xs">
                <Field :label="$t('con2_contracts_show.new_text')" :error="amend.errors.body"><textarea v-model="amend.body" rows="14" class="w-full rounded-xl border-slate-200 text-xs leading-relaxed" data-amend-body></textarea></Field>
                <Field :label="$t('con2_contracts_show.what_changed')"><input v-model="amend.note" maxlength="255" class="w-full rounded-xl border-slate-200 text-xs" data-amend-note /></Field>
            </div>
            <template #footer><button type="button" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold" @click="amending = false">{{ $t('con2_contracts_show.back') }}</button><button type="button" :disabled="amend.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" @click="doAmend" data-confirm-amend>{{ $t('con2_contracts_show.save_as_a_new_version') }}</button></template>
        </Dialog>

        <Dialog :show="!!viewing" :title="$t('con2_contracts_show.version_n', { n0: viewing?.version })" size="lg" @close="viewing = null">
            <p class="text-xs whitespace-pre-line leading-relaxed">{{ viewing?.body }}</p>
        </Dialog>
    </AppLayout>
</template>

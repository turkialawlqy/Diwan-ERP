<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { useDialog } from '@/composables/useDialog';

const props = defineProps({
    role: String, node: Number, queue: Number, cursor: Object, hubUrl: String, code: String,
    nodes: { type: Array, default: () => [] }, conflicts: { type: Array, default: () => [] },
});
const { t } = useI18n();
const { confirmAction } = useDialog();
const page = usePage();
const errors = computed(() => page.props.errors || {});
const busy = ref(false);

const reasons = computed(() => ({ id_collision: t('syncpage.reason_id_collision'), master_edited_on_branch: t('syncpage.reason_master_edited_on_branch'), closed_period: t('syncpage.reason_closed_period') }));
const cell = 'px-3 py-2 text-start';
const input = 'w-full rounded-xl border-slate-200 text-xs';
const card = 'bg-white border border-slate-200 rounded-2xl p-5 space-y-3';
const btn = 'rounded-xl bg-indigo-600 text-white font-bold px-4 py-2 disabled:opacity-50';

const post = (name, params = {}, data = {}) => {
    busy.value = true;
    router.post(route(name, params), data, { preserveScroll: true, onFinish: () => { busy.value = false; } });
};

const decide = async (c, action) => {
    if (await confirmAction(t(action === 'accept' ? 'syncpage.accept_confirm' : 'syncpage.reject_confirm'), { danger: action === 'accept' })) {
        post('settings.sync.resolve', { id: c.id, action });
    }
};
const enableHub = async () => { if (await confirmAction(t('syncpage.hub_confirm'))) post('settings.sync.hub'); };
const disconnect = async () => { if (await confirmAction(t('syncpage.disconnect_confirm'), { danger: true })) post('settings.sync.disconnect'); };
const removeBranch = async (n) => {
    if (await confirmAction(t('syncpage.remove_confirm'), { danger: true })) {
        busy.value = true;
        router.delete(route('settings.sync.branches.remove', { node: n.node_id }), { preserveScroll: true, onFinish: () => { busy.value = false; } });
    }
};

// Head office: a new branch.
const branchName = ref('');
const address = ref(props.hubUrl || '');
const addBranch = () => post('settings.sync.branches.add', {}, { name: branchName.value, hub_url: address.value });
const copied = ref(false);
const copyCode = async () => {
    try { await navigator.clipboard.writeText(props.code); copied.value = true; } catch { /* the code stays selectable */ }
};

// Branch: connect with the code.
const codeText = ref('');
const understood = ref(false);
const connect = async () => {
    if (await confirmAction(t('syncpage.branch_confirm_dialog'), { danger: true })) {
        post('settings.sync.connect', {}, { code: codeText.value, confirm: understood.value });
    }
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('syncpage.title')" />
        <PageHeader :title="$t('syncpage.title')" :crumbs="[{ label: $t('syncpage.crumb') }, { label: $t('syncpage.title') }]" />

        <div class="space-y-5 text-xs">
            <!-- Not linked yet: choose the role. -->
            <template v-if="role === 'off'">
                <section :class="card">
                    <h3 class="font-black text-slate-700">{{ $t('syncpage.link_title') }}</h3>
                    <p class="text-slate-500">{{ $t('syncpage.link_intro') }}</p>
                </section>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <section :class="card" data-sync-hub-card>
                        <h3 class="font-black text-slate-700">{{ $t('syncpage.hub_title') }}</h3>
                        <p class="text-slate-500 leading-relaxed">{{ $t('syncpage.hub_text') }}</p>
                        <button type="button" :class="btn" :disabled="busy" data-sync-enable-hub @click="enableHub">{{ $t('syncpage.hub_button') }}</button>
                    </section>
                    <section :class="card" data-sync-branch-card>
                        <h3 class="font-black text-slate-700">{{ $t('syncpage.branch_title') }}</h3>
                        <p class="text-slate-500 leading-relaxed">{{ $t('syncpage.branch_text') }}</p>
                        <label class="block font-bold text-slate-600">{{ $t('syncpage.code_label') }}
                            <textarea v-model="codeText" rows="3" dir="ltr" :class="input" :placeholder="$t('syncpage.code_placeholder')" data-sync-code-input />
                        </label>
                        <p v-if="errors.code" class="text-rose-600" data-sync-error>{{ errors.code }}</p>
                        <p class="text-amber-700 leading-relaxed">{{ $t('syncpage.branch_warning') }}</p>
                        <label class="flex items-center gap-2"><input v-model="understood" type="checkbox" data-sync-understood /> {{ $t('syncpage.branch_confirm') }}</label>
                        <button type="button" :class="btn" :disabled="busy || !understood || !codeText.trim()" data-sync-connect @click="connect">{{ $t('syncpage.branch_button') }}</button>
                    </section>
                </div>
            </template>

            <!-- Linked: status. -->
            <template v-else>
                <section :class="card" data-sync-status>
                    <p class="font-black text-slate-700">{{ role === 'hub' ? $t('syncpage.role_hub') : $t('syncpage.role_branch') }}</p>
                    <p>{{ $t('syncpage.node') }}: <span class="font-mono" dir="ltr">{{ node }}</span></p>
                    <template v-if="role === 'branch'">
                        <p>{{ $t('syncpage.queue') }}: <span class="font-mono font-bold" data-sync-queue>{{ queue }}</span></p>
                        <p v-if="cursor">{{ $t('syncpage.pushed') }}: <span class="font-mono">{{ cursor.pushed }}</span> · {{ $t('syncpage.pulled') }}: <span class="font-mono">{{ cursor.pulled }}</span></p>
                    </template>
                    <button type="button" class="font-bold text-rose-600 underline" :disabled="busy" data-sync-disconnect @click="disconnect">{{ $t('syncpage.disconnect') }}</button>
                </section>

                <section v-if="role === 'hub'" :class="card">
                    <h3 class="font-black text-slate-700">{{ $t('syncpage.branches') }}</h3>
                    <p v-if="!nodes.length" class="text-slate-500">{{ $t('syncpage.branches_none') }}</p>
                    <table v-else class="w-full">
                        <thead class="text-slate-500"><tr><th :class="cell">{{ $t('syncpage.node') }}</th><th :class="cell">{{ $t('syncpage.name') }}</th><th :class="cell">{{ $t('syncpage.last_seen') }}</th><th :class="cell" /></tr></thead>
                        <tbody>
                            <tr v-for="n in nodes" :key="n.node_id" class="border-t border-slate-100">
                                <td :class="cell" class="font-mono">{{ n.node_id }}</td><td :class="cell">{{ n.name }}</td>
                                <td :class="cell" dir="ltr">{{ n.last_seen_at || $t('syncpage.never') }}</td>
                                <td :class="cell"><button type="button" class="font-bold text-rose-600" @click="removeBranch(n)">{{ $t('syncpage.remove_branch') }}</button></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="border-t border-slate-100 pt-3 space-y-2" data-sync-add-branch>
                        <h4 class="font-black text-slate-700">{{ $t('syncpage.add_branch') }}</h4>
                        <label class="block font-bold text-slate-600">{{ $t('syncpage.branch_name') }}<input v-model="branchName" type="text" :class="input" data-sync-branch-name /></label>
                        <label class="block font-bold text-slate-600">{{ $t('syncpage.hub_url') }}<input v-model="address" type="text" dir="ltr" :class="input" data-sync-hub-url /></label>
                        <p class="text-slate-500">{{ $t('syncpage.hub_url_hint') }}</p>
                        <p v-if="errors.name || errors.hub_url" class="text-rose-600">{{ errors.name || errors.hub_url }}</p>
                        <button type="button" :class="btn" :disabled="busy || !branchName.trim() || !address.trim()" data-sync-create-code @click="addBranch">{{ $t('syncpage.create_code') }}</button>
                    </div>
                    <div v-if="code" class="rounded-xl border border-emerald-300 bg-emerald-50 p-3 space-y-2" data-sync-code-box>
                        <p class="font-bold text-emerald-800">{{ $t('syncpage.code_once') }}</p>
                        <textarea :value="code" rows="3" readonly dir="ltr" :class="input" data-sync-code-output @focus="$event.target.select()" />
                        <button type="button" class="font-bold underline" @click="copyCode">{{ copied ? $t('syncpage.code_copied') : $t('syncpage.code_copy') }}</button>
                        <p class="text-slate-600">{{ $t('syncpage.code_next') }}</p>
                    </div>
                </section>

                <section :class="card">
                    <h3 class="font-black text-slate-700">{{ $t('syncpage.conflicts') }}</h3>
                    <p v-if="!conflicts.length" class="text-slate-500" data-sync-no-conflicts>{{ $t('syncpage.no_conflicts') }}</p>
                    <table v-else class="w-full">
                        <thead class="text-slate-500"><tr>
                            <th :class="cell">{{ $t('syncpage.node') }}</th><th :class="cell">{{ $t('syncpage.table') }}</th><th :class="cell">{{ $t('syncpage.record') }}</th>
                            <th :class="cell">{{ $t('syncpage.op') }}</th><th :class="cell">{{ $t('syncpage.reason') }}</th><th :class="cell">{{ $t('syncpage.when') }}</th><th :class="cell">{{ $t('syncpage.actions') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="c in conflicts" :key="c.id" class="border-t border-slate-100" data-sync-conflict>
                                <td :class="cell" class="font-mono">{{ c.node_id }}</td><td :class="cell" class="font-mono" dir="ltr">{{ c.table_name }}</td>
                                <td :class="cell" class="font-mono">{{ c.pk }}</td><td :class="cell" class="font-mono">{{ c.op }}</td>
                                <td :class="cell">{{ reasons[c.reason] || c.reason }}</td>
                                <td :class="cell" dir="ltr">{{ c.created_at }}</td>
                                <td :class="cell" class="whitespace-nowrap">
                                    <button type="button" class="font-bold text-emerald-700 me-3" data-sync-accept @click="decide(c, 'accept')">{{ $t('syncpage.accept') }}</button>
                                    <button type="button" class="font-bold text-rose-600" data-sync-reject @click="decide(c, 'reject')">{{ $t('syncpage.reject') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>
        </div>
    </AppLayout>
</template>

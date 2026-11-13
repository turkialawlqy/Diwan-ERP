<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DocumentTable from '@/Components/Documents/DocumentTable.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { LockClosedIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

const props = defineProps({ sessions: Object, boxes: Array });
const { t } = useI18n();

const columns = computed(() => [
    { key: 'cashier', label: t('pos_open.cashier') },
    { key: 'register', label: t('pos_open.register') },
    { key: 'branch', label: t('pos_open.branch') },
    { key: 'opened_at', label: t('pos_open.opened_at'), type: 'datetime' },
    { key: 'opening_cash', label: t('pos_open.opening_cash'), type: 'money' },
    { key: 'expected_cash', label: t('pos_open.expected_cash'), type: 'money' },
]);

const closing = ref(null);
const form = useForm({ closing_cash: '', deposit_bank_account_id: '', notes: '' });

const startClose = (session) => {
    form.reset();
    form.clearErrors();
    closing.value = session;
};
const submit = () => form.post(route('pos.open-sessions.close', closing.value.id), {
    preserveScroll: true,
    onSuccess: () => { closing.value = null; },
});

// As on the terminal: the shift's own branch, or an account shared by all branches.
const boxesOfShift = computed(() => props.boxes.filter((b) => !b.branch_id || !closing.value?.branch_id || b.branch_id === closing.value.branch_id));

const difference = computed(() => {
    const counted = Number(form.closing_cash);
    return !closing.value || form.closing_cash === '' || Number.isNaN(counted) ? null : Math.round((counted - closing.value.expected_cash) * 100) / 100;
});
</script>

<template>
    <AppLayout own-errors>
        <Head :title="$t('pos_open.title')" />
        <PageHeader :title="$t('pos_open.title')" :subtitle="$t('pos_open.subtitle')" :crumbs="[{ label: $t('nav.pos') }, { label: $t('pos_open.title') }]" />

        <DocumentTable :paginator="sessions" :columns="columns">
            <template #actions="{ row }">
                <RowActions>
                    <button type="button" @click="startClose(row)" class="inline-flex items-center gap-1 px-2 py-1.5 rounded-lg text-rose-600 hover:bg-rose-50 font-bold" data-row-close>
                        <LockClosedIcon class="w-4 h-4" /> {{ $t('pos_open.close') }}
                    </button>
                </RowActions>
            </template>
        </DocumentTable>

        <Dialog :show="!!closing" :title="$t('pos_open.close_title')" @close="closing = null">
            <form v-if="closing" id="close-shift-form" class="space-y-4 text-xs" @submit.prevent="submit">
                <p v-if="form.errors.accounting" class="rounded-xl bg-rose-50 border border-rose-200 p-3 font-bold text-rose-700" data-close-error>{{ form.errors.accounting }}</p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl bg-slate-50 p-3">
                    <div><dt class="text-slate-500">{{ $t('pos_open.cashier') }}</dt><dd class="font-bold text-slate-800">{{ closing.cashier || '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ $t('pos_open.register') }}</dt><dd class="font-bold text-slate-800">{{ closing.register || '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ $t('pos_open.branch') }}</dt><dd class="font-bold text-slate-800">{{ closing.branch || '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ $t('pos_open.expected_cash') }}</dt><dd class="font-mono font-bold text-indigo-700" data-expected>{{ money(closing.expected_cash) }}</dd></div>
                </dl>

                <Field :label="$t('pos_open.counted')" :error="form.errors.closing_cash">
                    <input v-model="form.closing_cash" type="text" inputmode="decimal" data-num dir="ltr" required class="w-full rounded-xl border-slate-200 text-xs font-mono" />
                </Field>
                <p v-if="difference !== null" class="font-bold" :class="difference === 0 ? 'text-emerald-600' : 'text-rose-600'" data-difference>
                    {{ difference === 0 ? $t('pos_open.balanced') : $t(difference < 0 ? 'pos_open.shortage' : 'pos_open.overage', { amount: money(Math.abs(difference)) }) }}
                </p>

                <Field :label="$t('pos_open.deposit')" :error="form.errors.deposit_bank_account_id" :hint="$t('pos_open.deposit_hint')">
                    <SelectCreate v-model="form.deposit_bank_account_id" :options="boxesOfShift" entity="bank" label="name" :placeholder="$t('pos_open.deposit_none')" :empty-label="$t('pos_open.deposit_none')" />
                </Field>

                <Field :label="$t('pos_open.notes')" :error="form.errors.notes">
                    <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea>
                </Field>
            </form>
            <template #footer>
                <button type="button" @click="closing = null" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('ui.cancel') }}</button>
                <button type="submit" form="close-shift-form" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold disabled:opacity-50" data-confirm-close>{{ $t('pos_open.confirm') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

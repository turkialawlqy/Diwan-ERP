<script setup>
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import axios from 'axios';
import Dialog from '@/Components/UI/Dialog.vue';
import { money } from '@/utils/format';
import { ArrowUpTrayIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

/**
 * "Import from Excel" for document lines: the file is read on the server
 * (columns in Arabic or English, products matched by code, barcode or
 * name), the lines are previewed, then handed to the form with
 * @imported(lines, replace). Nothing is saved until the document is.
 */
const props = defineProps({
    kind: { type: String, default: 'sales' }, // sales | purchase | stock | journal
    customerId: { type: [Number, String, null], default: null },
    label: { type: String, default: '' },
});
const emit = defineEmits(['imported']);

const open = ref(false);
const loading = ref(false);
const result = ref(null);
const error = ref('');
const input = ref(null);

const pick = () => input.value?.click();
const upload = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    data.append('kind', props.kind);
    if (props.customerId) data.append('customer_id', props.customerId);
    loading.value = true;
    error.value = '';
    result.value = null;
    open.value = true;
    try {
        result.value = (await axios.post(route('lookup.lines.import'), data)).data;
    } catch (e) {
        error.value = e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : t('cmp.read_failed');
    } finally {
        loading.value = false;
    }
};
const apply = (replace) => {
    emit('imported', result.value.lines, replace);
    open.value = false;
};
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <button type="button" @click="pick" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-emerald-300 text-emerald-700 bg-emerald-50 text-xs font-bold" data-import-lines>
            <ArrowUpTrayIcon class="w-4 h-4" /> {{ label || $t('cmp.import_from_excel') }}
        </button>
        <a :href="route('lookup.lines.template', { kind })" class="text-3xs text-slate-500 underline">{{ $t('cmp.template') }}</a>
        <input ref="input" type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="upload" />

        <Dialog :show="open" :title="$t('cmp.import_lines_from_excel')" size="lg" @close="open = false">
            <p v-if="loading" class="text-xs text-slate-500">{{ $t('cmp.reading_the_file') }}</p>
            <p v-else-if="error" class="text-xs text-rose-600">{{ error }}</p>
            <div v-else-if="result" class="space-y-3 text-xs" data-import-preview>
                <div class="flex flex-wrap gap-2">
                    <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold">{{ $t('cmp.lines_ready', { n: result.lines.length }) }}</span>
                    <span v-if="result.summary.free" class="px-2 py-1 rounded-lg bg-amber-50 text-amber-700 font-bold">{{ $t('cmp.lines_free', { n: result.summary.free }) }}</span>
                    <span v-if="result.errors.length" class="px-2 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold">{{ $t('cmp.lines_rejected', { n: result.errors.length }) }}</span>
                    <span v-if="kind === 'journal'" class="px-2 py-1 rounded-lg bg-slate-100 font-bold" :class="result.summary.debit === result.summary.credit ? 'text-emerald-700' : 'text-rose-700'">{{ $t('cmp.debit') }} {{ money(result.summary.debit) }} / {{ $t('cmp.credit') }} {{ money(result.summary.credit) }}</span>
                </div>
                <ul v-if="result.errors.length" class="list-disc ps-5 text-rose-600 max-h-28 overflow-y-auto"><li v-for="(e, i) in result.errors" :key="i">{{ e }}</li></ul>
                <div class="overflow-x-auto max-h-72 overflow-y-auto border border-slate-100 rounded-xl">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500 sticky top-0">
                            <tr v-if="kind === 'journal'"><th class="p-2 text-start">{{ $t('cmp.account') }}</th><th class="p-2 text-end">{{ $t('cmp.debit') }}</th><th class="p-2 text-end">{{ $t('cmp.credit') }}</th></tr>
                            <tr v-else><th class="p-2 text-start">{{ $t('cmp.item') }}</th><th class="p-2 text-end">{{ $t('cmp.quantity') }}</th><th v-if="kind !== 'stock'" class="p-2 text-end">{{ $t('cmp.price') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in result.lines" :key="l.line" class="border-t border-slate-100">
                                <template v-if="kind === 'journal'">
                                    <td class="p-2"><span class="font-mono text-slate-400">{{ l.account_code }}</span> {{ l.account_name }}</td>
                                    <td class="p-2 text-end font-mono">{{ l.debit ? money(l.debit) : '' }}</td><td class="p-2 text-end font-mono">{{ l.credit ? money(l.credit) : '' }}</td>
                                </template>
                                <template v-else>
                                    <td class="p-2"><span v-if="l.sku" class="font-mono text-slate-400">{{ l.sku }}</span> {{ l.item_name }} <span v-if="!l.product_id" class="text-amber-600">{{ $t('cmp.free') }}</span></td>
                                    <td class="p-2 text-end font-mono">{{ l.quantity }}</td>
                                    <td v-if="kind !== 'stock'" class="p-2 text-end font-mono">{{ money(l.unit_price) }}</td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <template #footer>
                <template v-if="result && result.lines.length">
                    <button type="button" @click="apply(false)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('cmp.add_to_lines') }}</button>
                    <button type="button" @click="apply(true)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('cmp.replace_current_lines') }}</button>
                </template>
                <button v-else type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('cmp.close') }}</button>
            </template>
        </Dialog>
    </span>
</template>

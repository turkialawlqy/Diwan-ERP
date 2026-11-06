<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Dialog from '@/Components/UI/Dialog.vue';
import { ArrowUpTrayIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

/**
 * "Import from Excel" on a list screen: template, upload, and the rows that
 * were refused (nothing is saved unless every row is valid).
 */
const props = defineProps({
    type: { type: String, required: true }, // data-transfer type: customers, suppliers, products, quotations...
    title: { type: String, default: null },
    hint: { type: String, default: '' },
});

const open = ref(false);
const form = useForm({ file: null });
const lines = (message) => (message ? String(message).split('\n') : []);
const submit = () => form.post(route('data-transfer.import', props.type), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { open.value = false; form.reset(); },
});
</script>

<template>
    <!-- Always the last items of the header actions, i.e. the far end (top left in Arabic). -->
    <a :href="route('data-transfer.template', type)" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 bg-white text-slate-600 text-xs font-bold" data-template-button>
        <ArrowDownTrayIcon class="w-4 h-4" /> {{ $t('shared.download_template') }}
    </a>
    <button type="button" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-700 text-xs font-bold" data-import-button>
        <ArrowUpTrayIcon class="w-4 h-4" /> {{ $t('shared.import_excel') }}
    </button>
    <Dialog :show="open" :title="title || $t('shared.import_excel_title')" @close="open = false">
        <div class="space-y-3 text-xs">
            <p class="text-slate-600">{{ $t('shared.import_help') }}</p>
            <p v-if="hint" class="text-slate-500">{{ hint }}</p>
            <input type="file" accept=".csv,.txt,.xlsx,.xls" @input="form.file = $event.target.files[0]" class="block text-xs" />
            <ul v-if="form.errors.file" class="list-disc ps-5 text-rose-600 max-h-40 overflow-y-auto"><li v-for="(e, i) in lines(form.errors.file)" :key="i">{{ e }}</li></ul>
        </div>
        <template #footer>
            <button type="button" @click="submit" :disabled="!form.file || form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40">{{ $t('shared.import_btn') }}</button>
        </template>
    </Dialog>
</template>

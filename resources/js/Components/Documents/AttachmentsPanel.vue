<script setup>
import { useI18n } from 'vue-i18n';
import { computed, onMounted, ref } from 'vue';
import { PaperClipIcon, TrashIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';

/** Files attached to a document: list, upload (10 MB, documents/images/sheets) and delete. */
const props = defineProps({
    kind: { type: String, required: true },
    // A saved document: its id. Without it the panel works in «staged» mode: files are uploaded now and the
    // document claims them when it is saved (v-model carries the staged file ids, sent as attachment_ids).
    id: { type: [Number, String], default: null },
    modelValue: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);
const staged = computed(() => props.id === null || props.id === undefined || props.id === '');

const { t } = useI18n();
const items = ref([]);
const busy = ref(false);
const error = ref('');

const load = async () => {
    if (staged.value) return;
    try { items.value = (await axios.get(route('attachments.index', { kind: props.kind, id: props.id }))).data.data; } catch { items.value = []; }
};
const upload = async (event) => {
    const files = Array.from(event.target.files || []);
    if (!files.length) return;
    for (const file of files) await uploadOne(file, event);
    event.target.value = '';
};
const uploadOne = async (file, event) => {
    error.value = '';
    busy.value = true;
    const body = new FormData();
    body.append('file', file);
    try {
        if (staged.value) {
            const { data } = await axios.post(route('attachments.stage', { kind: props.kind }), body);
            items.value = [data.data, ...items.value];
            emit('update:modelValue', items.value.map((a) => a.id));
        } else {
            await axios.post(route('attachments.store', { kind: props.kind, id: props.id }), body);
            await load();
        }
    } catch (e) {
        error.value = e.response?.data?.errors?.file?.[0] || e.response?.data?.message || t('shared.upload_failed');
    } finally {
        busy.value = false;
    }
};
const remove = async (a) => {
    if (!window.confirm(t('shared.confirm_delete_attachment', { name: a.original_name }))) return;
    await axios.delete(route('attachments.destroy', a.id));
    if (staged.value) {
        items.value = items.value.filter((x) => x.id !== a.id);
        emit('update:modelValue', items.value.map((x) => x.id));
    } else {
        await load();
    }
};
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
onMounted(load);
</script>

<template>
    <section v-if="$can('admin.attachments.view')" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs print:hidden" data-attachments>
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-black text-slate-700 inline-flex items-center gap-1.5"><PaperClipIcon class="w-4 h-4" /> {{ $t('shared.attachments') }}</h3>
            <label v-if="$can('admin.attachments.create')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 font-bold cursor-pointer hover:bg-slate-50">
                <ArrowUpTrayIcon class="w-4 h-4" /> {{ busy ? $t('shared.uploading') : $t('shared.attach_file') }}
                <input type="file" multiple class="hidden" data-attachment-input accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.csv,.txt" :disabled="busy" @change="upload" />
            </label>
        </div>
        <p v-if="error" class="text-rose-600 font-bold mb-2" data-attachment-error>{{ error }}</p>
        <ul class="divide-y divide-slate-100">
            <li v-for="a in items" :key="a.id" class="flex items-center gap-3 py-1.5" data-attachment-row>
                <a :href="a.url" class="flex-1 font-bold text-indigo-700 hover:underline truncate">{{ a.original_name }}</a>
                <span class="text-slate-400 font-mono">{{ size(a.size) }}</span>
                <button v-if="$can('admin.attachments.delete')" type="button" class="p-1 text-slate-400 hover:text-rose-600" :title="$t('ui.delete')" @click="remove(a)"><TrashIcon class="w-4 h-4" /></button>
            </li>
            <li v-if="!items.length" class="py-3 text-center text-slate-400">{{ $t('shared.no_attachments') }}</li>
        </ul>
    </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { PaperClipIcon, TrashIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';

/** Files attached to a document: list, upload (10 MB, documents/images/sheets) and delete. */
const props = defineProps({ kind: { type: String, required: true }, id: { type: [Number, String], required: true } });

const items = ref([]);
const busy = ref(false);
const error = ref('');

const load = async () => {
    try { items.value = (await axios.get(route('attachments.index', { kind: props.kind, id: props.id }))).data.data; } catch { items.value = []; }
};
const upload = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    error.value = '';
    busy.value = true;
    const body = new FormData();
    body.append('file', file);
    try {
        await axios.post(route('attachments.store', { kind: props.kind, id: props.id }), body);
        await load();
    } catch (e) {
        error.value = e.response?.data?.errors?.file?.[0] || e.response?.data?.message || 'تعذّر رفع الملف';
    } finally {
        busy.value = false;
        event.target.value = '';
    }
};
const remove = async (a) => {
    if (!window.confirm(`حذف المرفق «${a.original_name}»؟`)) return;
    await axios.delete(route('attachments.destroy', a.id));
    await load();
};
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
onMounted(load);
</script>

<template>
    <section v-if="$can('admin.attachments.view')" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs print:hidden" data-attachments>
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-black text-slate-700 inline-flex items-center gap-1.5"><PaperClipIcon class="w-4 h-4" /> المرفقات</h3>
            <label v-if="$can('admin.attachments.create')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 font-bold cursor-pointer hover:bg-slate-50">
                <ArrowUpTrayIcon class="w-4 h-4" /> {{ busy ? 'جارٍ الرفع…' : 'إرفاق ملف' }}
                <input type="file" class="hidden" data-attachment-input accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.csv,.txt" :disabled="busy" @change="upload" />
            </label>
        </div>
        <p v-if="error" class="text-rose-600 font-bold mb-2" data-attachment-error>{{ error }}</p>
        <ul class="divide-y divide-slate-100">
            <li v-for="a in items" :key="a.id" class="flex items-center gap-3 py-1.5" data-attachment-row>
                <a :href="a.url" class="flex-1 font-bold text-indigo-700 hover:underline truncate">{{ a.original_name }}</a>
                <span class="text-slate-400 font-mono">{{ size(a.size) }}</span>
                <button v-if="$can('admin.attachments.delete')" type="button" class="p-1 text-slate-400 hover:text-rose-600" title="حذف" @click="remove(a)"><TrashIcon class="w-4 h-4" /></button>
            </li>
            <li v-if="!items.length" class="py-3 text-center text-slate-400">لا مرفقات</li>
        </ul>
    </section>
</template>

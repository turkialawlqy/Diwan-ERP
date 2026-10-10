<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { ArrowDownTrayIcon, PaperAirplaneIcon } from '@heroicons/vue/24/outline';

/**
 * "PDF" and "send to customer" (email with the PDF attached, or WhatsApp with
 * a signed link) for any document type registered in DocumentSharing::TYPES.
 */
const props = defineProps({
    type: { type: String, required: true },
    id: { type: [Number, String], required: true },
    compact: { type: Boolean, default: false },
});

const open = ref(false);
const loading = ref(false);
const sending = ref(false);
const channel = ref('email');
const form = ref({ to: '', cc: '', phone: '', message: '' });
const errors = ref({});
const notice = ref('');
const history = ref([]);

const pdfUrl = computed(() => route('documents.pdf', { type: props.type, id: props.id }));

const load = async () => {
    open.value = true;
    loading.value = true;
    errors.value = {};
    notice.value = '';
    try {
        const { data } = await axios.get(route('documents.dispatches', { type: props.type, id: props.id }));
        form.value = { to: data.defaults.to || '', cc: '', phone: data.defaults.phone || '', message: data.defaults.message || '' };
        history.value = data.history;
    } finally {
        loading.value = false;
    }
};

const send = async () => {
    sending.value = true;
    errors.value = {};
    notice.value = '';
    // Open the WhatsApp tab synchronously so pop-up blockers allow it.
    const waTab = channel.value === 'whatsapp' ? window.open('', '_blank') : null;
    try {
        const { data } = await axios.post(route('documents.send', { type: props.type, id: props.id }), { channel: channel.value, ...form.value });
        if (channel.value === 'whatsapp' && data.url) {
            if (waTab) waTab.location = data.url; else window.location.href = data.url;
            notice.value = 'تم فتح واتساب بالرسالة والرابط';
        } else {
            notice.value = data.message || 'تم الإرسال';
        }
        history.value = [data.dispatch, ...history.value];
    } catch (e) {
        waTab?.close();
        errors.value = e.response?.data?.errors || { general: [e.response?.data?.message || 'تعذّر الإرسال، حاول مرة أخرى'] };
    } finally {
        sending.value = false;
    }
};

const channels = { email: 'بريد', whatsapp: 'واتساب', link: 'رابط' };
const statuses = { sent: ['تم', 'text-emerald-700 bg-emerald-50'], queued: ['قيد الإرسال', 'text-amber-700 bg-amber-50'], failed: ['فشل', 'text-rose-700 bg-rose-50'] };
const when = (d) => (d ? new Date(d).toLocaleString('ar-SA-u-nu-latn', { dateStyle: 'short', timeStyle: 'short' }) : '');
</script>

<template>
    <a :href="pdfUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">
        <ArrowDownTrayIcon class="w-4 h-4" /><span>PDF</span>
    </a>
    <button type="button" @click="load" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold">
        <PaperAirplaneIcon class="w-4 h-4 rtl:-scale-x-100" /><span>إرسال للعميل</span>
    </button>

    <Dialog :show="open" title="إرسال للعميل" size="md" @close="open = false">
        <div v-if="loading" class="text-xs text-slate-500">جارٍ التحميل...</div>
        <div v-else class="space-y-3 text-xs">
            <div class="grid grid-cols-2 gap-2">
                <button v-for="(label, key) in { email: 'بريد إلكتروني (PDF مرفق)', whatsapp: 'واتساب (رابط PDF)' }" :key="key" type="button" @click="channel = key"
                    :class="['px-3 py-2 rounded-xl border font-bold', channel === key ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600']">{{ label }}</button>
            </div>
            <template v-if="channel === 'email'">
                <Field label="إلى" :error="errors.to?.[0]"><input v-model="form.to" type="email" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" placeholder="customer@example.com" /></Field>
                <Field label="نسخة إلى (اختياري، افصل بفاصلة)" :error="errors.cc?.[0]"><input v-model="form.cc" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </template>
            <Field v-else label="رقم الجوال" :error="errors.phone?.[0]" hint="يقبل 05XXXXXXXX أو 9665XXXXXXXX"><input v-model="form.phone" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="نص الرسالة" :error="errors.message?.[0]"><textarea v-model="form.message" rows="6" class="w-full rounded-xl border-slate-200 text-xs leading-6"></textarea></Field>
            <p v-if="errors.general" class="text-rose-600">{{ errors.general[0] }}</p>
            <p v-if="notice" class="text-emerald-700 font-bold">{{ notice }}</p>

            <div v-if="history.length" class="border-t border-slate-100 pt-3">
                <h4 class="font-bold text-slate-700 mb-2">سجل الإرسال</h4>
                <ul class="space-y-1.5 max-h-40 overflow-y-auto">
                    <li v-for="h in history" :key="h.id" class="flex items-center justify-between gap-2">
                        <span class="text-slate-600">{{ channels[h.channel] }} · <span dir="ltr">{{ h.recipient }}</span> <span class="text-slate-400">{{ when(h.created_at) }}{{ h.user ? ' · ' + h.user.name : '' }}</span></span>
                        <span :class="['px-2 py-0.5 rounded-full font-bold', statuses[h.status]?.[1]]" :title="h.error || ''">{{ statuses[h.status]?.[0] || h.status }}</span>
                    </li>
                </ul>
            </div>
        </div>
        <template #footer>
            <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إغلاق</button>
            <button type="button" @click="send" :disabled="sending || loading" class="px-5 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold disabled:opacity-50">{{ sending ? 'جارٍ الإرسال...' : (channel === 'email' ? 'إرسال البريد' : 'فتح واتساب') }}</button>
        </template>
    </Dialog>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import MobileLayout from '@/Layouts/MobileLayout.vue';
import SignaturePad from '@/Components/WorkOrders/SignaturePad.vue';
import { PhoneIcon, MapPinIcon, CameraIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ order: Object, running: Object, minutes: Number, rules: Object, statuses: Object, open: Boolean });
const o = computed(() => props.order);
const mine = computed(() => props.running && props.running.work_order_id === props.order.id);
const otherRunning = computed(() => props.running && props.running.work_order_id !== props.order.id);
const act = (name, data = {}) => router.post(route(name, o.value.id), data, { preserveScroll: true });

// Elapsed time of the running timer, from the server's start (not from page load).
const now = ref(Date.now());
let tick;
onMounted(() => { tick = setInterval(() => (now.value = Date.now()), 1000); });
onBeforeUnmount(() => clearInterval(tick));
const elapsed = computed(() => {
    if (!mine.value) return '';
    const s = Math.max(0, Math.floor((now.value - new Date(props.running.started_at).getTime()) / 1000));
    return [Math.floor(s / 3600), Math.floor((s % 3600) / 60), s % 60].map((n) => String(n).padStart(2, '0')).join(':');
});

const toggleTask = (t) => router.post(route('work-orders.mine.task', { order: o.value.id, task: t.id }), {}, { preserveScroll: true });

// Parts: search then add.
const term = ref('');
const found = ref([]);
const partForm = useForm({ product_id: '', quantity: 1 });
const picked = ref(null);
let timer;
const search = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (!term.value.trim()) { found.value = []; return; }
        const res = await fetch(`${route('work-orders.mine.products')}?q=${encodeURIComponent(term.value)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        found.value = res.ok ? (await res.json()).data : [];
    }, 250);
};
const choose = (p) => { picked.value = p; partForm.product_id = p.id; found.value = []; term.value = p.name_ar; };
const addPart = () => partForm.post(route('work-orders.mine.part', o.value.id), { preserveScroll: true, onSuccess: () => { partForm.reset(); picked.value = null; term.value = ''; } });

const upload = (e) => {
    const file = e.target.files?.[0];
    if (file) router.post(route('work-orders.mine.photo', o.value.id), { file }, { forceFormData: true, preserveScroll: true });
    e.target.value = '';
};

const sign = useForm({ name: '', signature: '' });
const doSign = () => sign.post(route('work-orders.mine.sign', o.value.id), { preserveScroll: true });
const finish = useForm({ notes: props.order.completion_notes || '' });
const doComplete = () => { if (confirm('إنهاء أمر العمل؟')) finish.post(route('work-orders.mine.complete', o.value.id)); };
const hours = computed(() => `${Math.floor(props.minutes / 60)}س ${props.minutes % 60}د`);
const input = 'w-full rounded-xl border-slate-200 text-sm';
</script>

<template>
    <Head :title="order.number" />
    <MobileLayout :title="`${order.number} — ${order.title}`" :back="route('work-orders.mine.index')">
        <div class="bg-white rounded-2xl p-4 shadow-sm space-y-2 text-xs">
            <div class="flex justify-between"><b class="text-sm">{{ order.customer?.name }}</b><span class="font-bold" data-status>{{ statuses[order.status] }}</span></div>
            <p v-if="order.description" class="whitespace-pre-line text-slate-600">{{ order.description }}</p>
            <a v-if="order.site_phone || order.customer?.phone" :href="`tel:${order.site_phone || order.customer.phone}`" class="flex items-center gap-1.5 text-indigo-700 font-bold"><PhoneIcon class="w-4 h-4" /> {{ order.site_phone || order.customer.phone }}</a>
            <a v-if="order.site_address" :href="`https://maps.google.com/?q=${encodeURIComponent(order.site_address)}`" target="_blank" class="flex items-center gap-1.5 text-indigo-700 font-bold"><MapPinIcon class="w-4 h-4" /> {{ order.site_address }}</a>
        </div>

        <div v-if="open" class="bg-white rounded-2xl p-4 shadow-sm space-y-2">
            <p class="text-xs text-slate-500">الوقت المسجَّل: <b class="font-mono" data-minutes>{{ hours }}</b></p>
            <p v-if="mine" class="text-3xl font-black font-mono text-center text-emerald-600" data-elapsed>{{ elapsed }}</p>
            <button v-if="mine" type="button" class="w-full py-4 rounded-2xl bg-rose-600 text-white font-black text-base" @click="act('work-orders.mine.stop')" data-stop>إيقاف المؤقت</button>
            <button v-else type="button" :disabled="otherRunning" class="w-full py-4 rounded-2xl bg-emerald-600 text-white font-black text-base disabled:opacity-40" @click="act('work-orders.mine.start')" data-start>بدء العمل</button>
            <p v-if="otherRunning" class="text-3xs text-amber-700 font-bold">عندك مؤقت يعمل على أمر آخر؛ أوقفه أولًا.</p>
            <button v-if="order.status === 'in_progress' && !mine" type="button" class="w-full py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600" @click="act('work-orders.mine.hold')">تعليق الأمر</button>
        </div>

        <div v-if="order.tasks.length" class="bg-white rounded-2xl p-4 shadow-sm space-y-1" data-tasks>
            <p class="text-xs font-bold mb-1">قائمة المهام</p>
            <button v-for="t in order.tasks" :key="t.id" type="button" :disabled="!open" class="w-full flex items-center gap-3 py-2 text-start text-sm" :data-task="t.title" @click="toggleTask(t)">
                <span class="w-6 h-6 rounded-lg border-2 flex items-center justify-center text-white text-xs" :class="t.is_done ? 'bg-emerald-600 border-emerald-600' : 'border-slate-300'">{{ t.is_done ? '✓' : '' }}</span>
                <span :class="t.is_done ? 'line-through text-slate-400' : ''">{{ t.title }}</span>
            </button>
        </div>

        <div class="bg-white rounded-2xl p-4 shadow-sm space-y-2">
            <p class="text-xs font-bold">القطع المستهلكة</p>
            <p v-for="p in order.parts" :key="p.id" class="text-xs flex justify-between" data-part><span>{{ p.name }}</span><b class="font-mono">× {{ p.quantity }}</b></p>
            <template v-if="open">
                <div class="relative">
                    <input v-model="term" :class="input" placeholder="ابحث عن قطعة بالاسم أو الباركود" @input="search" data-part-search />
                    <div v-if="found.length" class="absolute inset-x-0 z-10 mt-1 bg-white border border-slate-200 rounded-xl shadow max-h-56 overflow-y-auto">
                        <button v-for="p in found" :key="p.id" type="button" class="w-full text-start px-3 py-2 text-sm hover:bg-slate-50" @click="choose(p)">{{ p.name_ar }} <span class="font-mono text-slate-400 text-xs">{{ p.sku }}</span></button>
                    </div>
                </div>
                <div class="flex gap-2"><input v-model="partForm.quantity" type="text" inputmode="decimal" data-num :class="input" class="w-24 text-center font-mono" data-part-qty /><button type="button" :disabled="!partForm.product_id || partForm.processing" class="flex-1 py-2 rounded-xl bg-indigo-600 text-white font-bold text-sm disabled:opacity-40" @click="addPart" data-add-part>إضافة القطعة</button></div>
                <p v-if="partForm.errors.quantity || partForm.errors.product_id" class="text-xs text-rose-600 font-bold">{{ partForm.errors.quantity || partForm.errors.product_id }}</p>
            </template>
        </div>

        <div class="bg-white rounded-2xl p-4 shadow-sm space-y-2">
            <p class="text-xs font-bold">الصور</p>
            <div class="flex flex-wrap gap-2"><a v-for="ph in order.photos" :key="ph.id" :href="ph.url" target="_blank"><img :src="ph.url" class="w-16 h-16 object-cover rounded-lg border" alt="" /></a></div>
            <label v-if="open" class="flex items-center justify-center gap-2 py-3 rounded-xl border border-dashed border-slate-300 text-sm font-bold text-slate-600"><CameraIcon class="w-5 h-5" /> التقاط / رفع صورة<input type="file" accept="image/*" capture="environment" class="sr-only" @change="upload" data-photo-input /></label>
        </div>

        <div v-if="open" class="bg-white rounded-2xl p-4 shadow-sm space-y-2">
            <p class="text-xs font-bold">توقيع العميل<span v-if="rules.require_signature" class="text-rose-600"> *</span></p>
            <p v-if="order.signed" class="text-xs text-emerald-700 font-bold" data-signed>وقّع {{ order.signed_by }} ✓</p>
            <template v-else>
                <input v-model="sign.name" :class="input" placeholder="اسم الموقّع" data-sign-name />
                <SignaturePad v-model="sign.signature" />
                <button type="button" :disabled="!sign.signature || !sign.name || sign.processing" class="w-full py-2 rounded-xl border border-indigo-200 text-indigo-700 font-bold text-sm disabled:opacity-40" @click="doSign" data-save-signature>حفظ التوقيع</button>
            </template>
        </div>

        <div v-if="open" class="bg-white rounded-2xl p-4 shadow-sm space-y-2">
            <textarea v-model="finish.notes" rows="3" :class="input" placeholder="ملاحظات الإنجاز" data-notes></textarea>
            <button type="button" :disabled="finish.processing || mine" class="w-full py-4 rounded-2xl bg-indigo-700 text-white font-black text-base disabled:opacity-40" @click="doComplete" data-complete>إنهاء الأمر</button>
            <p v-if="mine" class="text-3xs text-slate-500">أوقف المؤقت قبل الإنهاء.</p>
        </div>
    </MobileLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import FreeformElement from '@/Components/Freeform/FreeformElement.vue';
import ElementProps from '@/Components/Freeform/ElementProps.vue';
import FieldPicker from '@/Components/Freeform/FieldPicker.vue';
import { MM, newElement, newId, onPage, rowsPerPage } from '@/Components/Freeform/freeform';

/**
 * Free-form print designer: every element (text with fields, boxes, lines,
 * images, QR, the items table) is placed and sized freely on the page in
 * millimetres. The canvas follows the PDF renderer's rules; "معاينة PDF"
 * renders the unsaved design on the server exactly as it will print.
 */
const props = defineProps({
    template: Object, layout: Object, catalogue: Object, documents: Array, preview: Object,
    fonts: Array, papers: Object, logo: String, kinds: Object,
});

const name = ref(props.template.name);
const layout = reactive(JSON.parse(JSON.stringify(props.layout)));
const data = ref(props.preview);
const documentId = ref(props.preview?.source?.id || '');
const zoom = ref(1);
const showGrid = ref(true);
const snap = ref(true);
const showFields = ref(false);
const previewPage = ref('first');
const selected = ref([]);
const guides = ref([]);
const saving = ref(false);
const message = ref(null);
const pageEl = ref(null);
const qrUrl = ref(null);
const imageUrls = reactive({});
const paletteTab = ref('add');

const page = computed(() => layout.page);
const sel = computed(() => layout.elements.filter((e) => selected.value.includes(e.id)));
const single = computed(() => (sel.value.length === 1 ? sel.value[0] : null));
const labels = computed(() => (showFields.value ? Object.fromEntries(props.catalogue.groups.flatMap((g) => g.fields.map((f) => [f.key, f.label]))) : null));
const imageUrl = (src) => (src ? imageUrls[src] || route('templates.image', src.split('/').pop()) : null);

// ---------------------------------------------------------------- fonts
onMounted(() => {
    const css = props.fonts.map((f) => `@font-face{font-family:'ff-${f.key}';src:url('${f.url}') format('truetype');font-weight:normal;}`
        + (f.bold ? `@font-face{font-family:'ff-${f.key}';src:url('${f.bold}') format('truetype');font-weight:bold;}` : '')).join('');
    const style = document.createElement('style');
    style.dataset.freeform = '1';
    style.textContent = css;
    document.head.appendChild(style);
    fitZoom();
});

// ---------------------------------------------------------------- preview data
const table = computed(() => layout.elements.find((e) => e.type === 'table'));
const chunks = computed(() => {
    const items = data.value?.items || [];
    if (!table.value || !items.length) return [items];
    const per = rowsPerPage(table.value);
    const out = [];
    for (let i = 0; i < items.length; i += per) out.push(items.slice(i, i + per));
    return out;
});
const pageIndex = computed(() => (previewPage.value === 'last' ? chunks.value.length - 1 : 0));
const rows = computed(() => chunks.value[pageIndex.value] || []);
const fields = computed(() => ({ ...(data.value?.fields || {}), 'page.number': String(pageIndex.value + 1), 'page.total': String(chunks.value.length) }));
watch(() => data.value?.qr, async (qr) => { qrUrl.value = qr ? await QRCode.toDataURL(qr, { margin: 0, width: 300 }) : null; }, { immediate: true });
const loadDocument = async () => {
    const { data: d } = await axios.get(route('templates.data', props.template.id), { params: { document: documentId.value || undefined } });
    data.value = d;
};
const visible = (el) => showFields.value || onPage(el.pages, pageIndex.value, chunks.value.length);

// ---------------------------------------------------------------- history
const history = ref([JSON.stringify(layout)]);
const cursor = ref(0);
const saved = ref(JSON.stringify(layout));
let restoring = false;
let timer = null;
watch(layout, () => {
    if (restoring) return;
    clearTimeout(timer);
    timer = setTimeout(() => {
        const snap = JSON.stringify(layout);
        if (snap === history.value[cursor.value]) return;
        history.value = history.value.slice(0, cursor.value + 1).concat(snap).slice(-100);
        cursor.value = history.value.length - 1;
    }, 350);
}, { deep: true });
const restore = (i) => {
    restoring = true;
    const snap = JSON.parse(history.value[i]);
    layout.page = snap.page;
    layout.grid = snap.grid;
    layout.elements = snap.elements;
    cursor.value = i;
    selected.value = selected.value.filter((id) => layout.elements.some((e) => e.id === id));
    nextTick(() => { restoring = false; });
};
const undo = () => cursor.value > 0 && restore(cursor.value - 1);
const redo = () => cursor.value < history.value.length - 1 && restore(cursor.value + 1);
const dirty = computed(() => JSON.stringify(layout) !== saved.value || name.value !== props.template.name);
const beforeUnload = (e) => { if (dirty.value) { e.preventDefault(); e.returnValue = ''; } };
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

// ---------------------------------------------------------------- elements
let addOffset = 0;
const add = (type, extra = {}) => {
    if (type === 'table' && table.value) { flash('يوجد جدول أصناف بالفعل؛ عدّل أعمدته من الخصائص.', true); select(table.value); return; }
    addOffset = (addOffset + 6) % 36;
    const el = newElement(type, { x: 20 + addOffset, y: 20 + addOffset }, extra);
    if (type === 'table') { el.x = 10; el.w = page.value.width - 20; }
    layout.elements.push(el);
    selected.value = [el.id];
};
const addField = (key, label) => add('text', { content: `{{${key}}}`, name: label, w: 55, h: 7 });
const addLabeledField = (key, label) => add('text', { content: `${label}: {{${key}}}`, name: label, w: 70, h: 7 });
const select = (el, additive = false) => {
    if (additive) selected.value = selected.value.includes(el.id) ? selected.value.filter((x) => x !== el.id) : [...selected.value, el.id];
    else if (!selected.value.includes(el.id)) selected.value = [el.id];
};
const removeSelected = () => { layout.elements = layout.elements.filter((e) => !selected.value.includes(e.id)); selected.value = []; };
const duplicate = () => {
    const copies = sel.value.map((e) => ({ ...JSON.parse(JSON.stringify(e)), id: newId(), x: e.x + 4, y: e.y + 4, locked: false })).filter((e) => e.type !== 'table');
    layout.elements.push(...copies);
    selected.value = copies.map((c) => c.id);
};
let clipboard = [];
const copy = () => { clipboard = JSON.parse(JSON.stringify(sel.value.filter((e) => e.type !== 'table'))); };
const paste = () => {
    const copies = clipboard.map((e) => ({ ...JSON.parse(JSON.stringify(e)), id: newId(), x: e.x + 5, y: e.y + 5 }));
    layout.elements.push(...copies);
    selected.value = copies.map((c) => c.id);
};
const toFront = () => { const keep = layout.elements.filter((e) => !selected.value.includes(e.id)); layout.elements = [...keep, ...sel.value]; };
const toBack = () => { const keep = layout.elements.filter((e) => !selected.value.includes(e.id)); layout.elements = [...sel.value, ...keep]; };
const align = (how) => {
    const items = sel.value;
    const minX = Math.min(...items.map((e) => e.x)); const maxX = Math.max(...items.map((e) => e.x + e.w));
    const minY = Math.min(...items.map((e) => e.y)); const maxY = Math.max(...items.map((e) => e.y + e.h));
    items.forEach((e) => {
        if (how === 'left') e.x = minX;
        if (how === 'right') e.x = r2(maxX - e.w);
        if (how === 'top') e.y = minY;
        if (how === 'bottom') e.y = r2(maxY - e.h);
        if (how === 'hcenter') e.x = r2((minX + maxX) / 2 - e.w / 2);
        if (how === 'vcenter') e.y = r2((minY + maxY) / 2 - e.h / 2);
        if (how === 'width') e.w = items[0].w;
        if (how === 'height') e.h = items[0].h;
    });
};
const r2 = (n) => Math.round(n * 100) / 100;

// ---------------------------------------------------------------- drag & resize
const toMm = (px) => px / (MM * zoom.value);
const snapTo = (v) => (snap.value ? r2(Math.round(v / layout.grid) * layout.grid) : r2(v));
let drag = null;

// Edges and centres of the other elements (and the page) to align with.
const targets = () => {
    const others = layout.elements.filter((e) => !selected.value.includes(e.id) && visible(e));
    return {
        x: [0, page.value.width / 2, page.value.width, ...others.flatMap((e) => [e.x, e.x + e.w / 2, e.x + e.w])],
        y: [0, page.value.height / 2, page.value.height, ...others.flatMap((e) => [e.y, e.y + e.h / 2, e.y + e.h])],
    };
};
const nearest = (values, list, tol) => {
    let best = null;
    values.forEach((v, i) => list.forEach((t) => { const d = t - v; if (Math.abs(d) <= tol && (!best || Math.abs(d) < Math.abs(best.d))) best = { d, at: t, i }; }));
    return best;
};

const onElementDown = (e, el) => {
    if (e.button !== 0) return;
    select(el, e.shiftKey);
    if (e.shiftKey || el.locked) return;
    drag = { mode: 'move', x: e.clientX, y: e.clientY, primary: el, start: sel.value.filter((s) => !s.locked).map((s) => ({ el: s, x: s.x, y: s.y })), t: targets() };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp, { once: true });
};
const onHandleDown = (e, el, dir) => {
    drag = { mode: 'resize', dir, x: e.clientX, y: e.clientY, el, orig: { x: el.x, y: el.y, w: el.w, h: el.h } };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp, { once: true });
};
const onMove = (e) => {
    if (!drag) return;
    const dx = toMm(e.clientX - drag.x);
    const dy = toMm(e.clientY - drag.y);
    guides.value = [];
    if (drag.mode === 'move') {
        const p = drag.start.find((s) => s.el === drag.primary) || drag.start[0];
        if (!p) return;
        let nx = p.x + dx; let ny = p.y + dy;
        const tol = 1.5 / zoom.value;
        const gx = snap.value ? nearest([nx, nx + p.el.w / 2, nx + p.el.w], drag.t.x, tol) : null;
        const gy = snap.value ? nearest([ny, ny + p.el.h / 2, ny + p.el.h], drag.t.y, tol) : null;
        nx = gx ? r2(nx + gx.d) : snapTo(nx);
        ny = gy ? r2(ny + gy.d) : snapTo(ny);
        if (gx) guides.value.push({ v: true, at: gx.at });
        if (gy) guides.value.push({ v: false, at: gy.at });
        drag.start.forEach((s) => { s.el.x = r2(s.x + (nx - p.x)); s.el.y = r2(s.y + (ny - p.y)); });
    } else {
        const { orig, el, dir } = drag;
        const min = 1;
        let { x, y, w, h } = orig;
        if (dir.includes('e')) w = Math.max(min, snapTo(orig.x + orig.w + dx) - orig.x);
        if (dir.includes('w')) { const nx = Math.min(snapTo(orig.x + dx), orig.x + orig.w - min); w = orig.x + orig.w - nx; x = nx; }
        if (dir.includes('s')) h = Math.max(min, snapTo(orig.y + orig.h + dy) - orig.y);
        if (dir.includes('n')) { const ny = Math.min(snapTo(orig.y + dy), orig.y + orig.h - min); h = orig.y + orig.h - ny; y = ny; }
        Object.assign(el, { x: r2(x), y: r2(y), w: r2(w), h: r2(h) });
    }
};
const onUp = () => { window.removeEventListener('pointermove', onMove); drag = null; guides.value = []; };
const handles = (el) => (el.type === 'line' ? (el.orientation === 'v' ? ['n', 's'] : ['e', 'w']) : ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw']);
const handleStyle = (d) => ({
    top: d.includes('n') ? '-4px' : d.includes('s') ? 'calc(100% - 4px)' : 'calc(50% - 4px)',
    left: d.includes('w') ? '-4px' : d.includes('e') ? 'calc(100% - 4px)' : 'calc(50% - 4px)',
    cursor: `${d}-resize`,
    transform: `scale(${1 / zoom.value})`,
});

// ---------------------------------------------------------------- keyboard
const onKey = (e) => {
    const tag = e.target?.tagName;
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || e.target?.isContentEditable;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
    if (typing) return;
    if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
    if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
    if (mod && e.key.toLowerCase() === 'a') { e.preventDefault(); selected.value = layout.elements.filter(visible).map((x) => x.id); return; }
    if (!sel.value.length) return;
    if (mod && e.key.toLowerCase() === 'c') { copy(); return; }
    if (mod && e.key.toLowerCase() === 'v') { e.preventDefault(); paste(); return; }
    if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicate(); return; }
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeSelected(); return; }
    if (e.key === 'Escape') { selected.value = []; return; }
    const step = e.shiftKey ? 5 : 0.5;
    const move = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
    if (move) { e.preventDefault(); sel.value.filter((x) => !x.locked).forEach((x) => { x.x = r2(x.x + move[0]); x.y = r2(x.y + move[1]); }); }
};
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

// ---------------------------------------------------------------- zoom
const scroller = ref(null);
const fitZoom = () => {
    const w = (scroller.value?.clientWidth || 900) - 64;
    zoom.value = Math.max(0.4, Math.min(1.5, r2(w / (page.value.width * MM))));
};
const canvasSize = computed(() => ({ width: `${page.value.width * MM * zoom.value}px`, height: `${page.value.height * MM * zoom.value}px` }));
const gridStyle = computed(() => (showGrid.value ? {
    backgroundImage: 'linear-gradient(to right, rgba(99,102,241,.08) 1px, transparent 1px), linear-gradient(to bottom, rgba(99,102,241,.08) 1px, transparent 1px)',
    backgroundSize: `${Math.max(layout.grid, 5)}mm ${Math.max(layout.grid, 5)}mm`,
} : {}));

// ---------------------------------------------------------------- page settings
const setPaper = (size) => {
    layout.page.size = size;
    if (size !== 'custom') {
        const [w, h] = props.papers[size];
        [layout.page.width, layout.page.height] = layout.page.orientation === 'landscape' ? [h, w] : [w, h];
    }
};
const setOrientation = (o) => {
    if (o === layout.page.orientation) return;
    layout.page.orientation = o;
    [layout.page.width, layout.page.height] = [layout.page.height, layout.page.width];
};

// ---------------------------------------------------------------- server
const upload = async (file) => {
    const form = new FormData();
    form.append('image', file);
    try {
        const { data: d } = await axios.post(route('templates.images'), form);
        imageUrls[d.src] = d.url;
        return d.src;
    } catch (e) {
        flash(e.response?.data?.errors?.image?.[0] || 'تعذّر رفع الصورة', true);
        throw e;
    }
};
const uploadBackground = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    try { layout.page.background.src = await upload(file); } finally { e.target.value = ''; }
};
const save = () => {
    saving.value = true;
    router.put(route('templates.update', props.template.id), { name: name.value, freeform: JSON.parse(JSON.stringify(layout)) }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { saved.value = JSON.stringify(layout); flash('تم حفظ التصميم'); },
        onError: (errors) => flash(Object.values(errors)[0] || 'تعذّر الحفظ', true),
        onFinish: () => { saving.value = false; },
    });
};
const pdfPreview = async () => {
    const win = window.open('', '_blank');
    try {
        const res = await axios.post(route('templates.preview', props.template.id), { freeform: layout, document: documentId.value || null }, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        if (win) win.location = url; else window.location = url;
    } catch {
        win?.close();
        flash('تعذّرت المعاينة', true);
    }
};
const startOver = async () => {
    if (!confirm('سيُستبدل التصميم الحالي بالتصميم الجاهز لهذا النوع. يمكنك التراجع بـ Ctrl+Z. متابعة؟')) return;
    const { data: preset } = await axios.get(route('templates.preset', props.template.id));
    layout.page = preset.page; layout.grid = preset.grid; layout.elements = preset.elements;
    selected.value = [];
};
const clearAll = () => { if (confirm('حذف كل العناصر والبدء بصفحة فارغة؟')) { layout.elements = []; selected.value = []; } };
const setDefault = () => router.post(route('templates.default', props.template.id), {}, { preserveScroll: true, onSuccess: () => flash('أصبح هذا القالب هو المستخدم في الطباعة') });
let flashTimer = null;
const flash = (text, error = false) => { message.value = { text, error }; clearTimeout(flashTimer); flashTimer = setTimeout(() => { message.value = null; }, 3500); };
const serverFlash = computed(() => usePage().props.flash);
watch(() => serverFlash.value?.success, (v) => v && flash(v));

const typeLabel = { text: 'نص', box: 'مستطيل', line: 'خط', image: 'صورة', qr: 'QR', table: 'جدول' };
</script>

<template>
    <Head :title="`تصميم: ${name}`" />
    <div class="h-screen flex flex-col bg-slate-100 text-slate-800" dir="rtl">
        <!-- Top bar -->
        <header class="flex flex-wrap items-center gap-2 px-4 py-2 bg-white border-b border-slate-200 text-xs">
            <Link :href="route('templates.index')" class="px-3 py-2 rounded-xl border border-slate-200 font-bold">→ القوالب</Link>
            <input v-model="name" aria-label="اسم القالب" class="rounded-xl border-slate-200 text-sm font-bold w-56" />
            <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-600 font-bold">{{ catalogue.label }}</span>
            <span v-if="template.is_default" class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold">مستخدم في الطباعة</span>
            <button v-else type="button" @click="setDefault" class="px-3 py-2 rounded-xl border border-emerald-200 text-emerald-700 font-bold">استخدامه في الطباعة</button>
            <div class="flex-1"></div>
            <button type="button" @click="undo" :disabled="cursor === 0" class="px-3 py-2 rounded-xl border border-slate-200 font-bold disabled:opacity-30" title="Ctrl+Z">↶ تراجع</button>
            <button type="button" @click="redo" :disabled="cursor >= history.length - 1" class="px-3 py-2 rounded-xl border border-slate-200 font-bold disabled:opacity-30" title="Ctrl+Y">↷ إعادة</button>
            <div class="flex items-center gap-1 px-2 border-x border-slate-200">
                <button type="button" @click="zoom = Math.max(0.3, r2(zoom - 0.1))" class="w-7 h-7 rounded-lg border border-slate-200" aria-label="تصغير">−</button>
                <button type="button" @click="fitZoom" class="w-14 font-mono" title="ملاءمة">{{ Math.round(zoom * 100) }}%</button>
                <button type="button" @click="zoom = Math.min(3, r2(zoom + 0.1))" class="w-7 h-7 rounded-lg border border-slate-200" aria-label="تكبير">+</button>
            </div>
            <button type="button" @click="pdfPreview" class="px-3 py-2 rounded-xl border border-indigo-200 text-indigo-700 font-bold">معاينة PDF</button>
            <button type="button" @click="save" :disabled="saving" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50">{{ saving ? 'جارٍ الحفظ...' : 'حفظ' }}<span v-if="dirty" class="ms-1">•</span></button>
        </header>
        <div v-if="message" role="status" :class="['absolute top-14 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-xl text-xs font-bold shadow', message.error ? 'bg-rose-600 text-white' : 'bg-emerald-600 text-white']">{{ message.text }}</div>

        <div class="flex-1 flex min-h-0">
            <!-- Palette (right) -->
            <aside class="w-64 shrink-0 bg-white border-l border-slate-200 flex flex-col text-xs">
                <div class="flex border-b border-slate-200" role="tablist">
                    <button v-for="[k, l] in [['add', 'إضافة'], ['fields', 'الحقول'], ['layers', 'العناصر']]" :key="k" type="button" role="tab" :aria-selected="paletteTab === k" @click="paletteTab = k" :class="['flex-1 py-2.5 font-bold border-b-2', paletteTab === k ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500']">{{ l }}</button>
                </div>
                <div class="flex-1 overflow-y-auto p-3">
                    <div v-if="paletteTab === 'add'" class="grid grid-cols-2 gap-2">
                        <button v-for="[t, l, extra] in [['text', 'نص'], ['box', 'مستطيل / إطار'], ['line', 'خط أفقي'], ['vline', 'خط رأسي'], ['image', 'الشعار', { src: 'logo' }], ['image', 'صورة', { src: null, name: 'صورة' }], ['qr', 'رمز QR'], ...(catalogue.has_items ? [['table', 'جدول الأصناف']] : [])]" :key="l" type="button" @click="add(t, extra || {})" class="py-3 rounded-xl border border-slate-200 bg-slate-50 hover:border-indigo-400 hover:bg-indigo-50 font-bold">{{ l }}</button>
                        <p class="col-span-2 text-slate-400 leading-5 mt-2">اسحب العناصر بالفأرة، وغيّر حجمها من المربعات الصغيرة حول العنصر. Shift + نقر لتحديد أكثر من عنصر، والأسهم للتحريك الدقيق.</p>
                    </div>
                    <div v-else-if="paletteTab === 'fields'" class="space-y-2">
                        <p class="text-slate-500">انقر حقلاً لإضافته للصفحة. مع <b>عنوان</b> يضيف «العنوان: القيمة» في نفس المربع.</p>
                        <FieldPicker :catalogue="catalogue" @pick="(k, l) => addLabeledField(k, l)" />
                        <details class="mt-2"><summary class="cursor-pointer font-bold text-slate-600">إضافة القيمة فقط بدون عنوان</summary><div class="mt-2"><FieldPicker :catalogue="catalogue" compact @pick="addField" /></div></details>
                    </div>
                    <ul v-else class="space-y-1">
                        <li v-for="el in [...layout.elements].reverse()" :key="el.id">
                            <button type="button" @click="(e) => select(el, e.shiftKey)" :class="['w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-start', selected.includes(el.id) ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-slate-50']">
                                <span class="w-12 shrink-0 text-[10px] text-slate-400">{{ typeLabel[el.type] }}</span>
                                <span class="truncate flex-1">{{ el.name || el.content || el.id }}</span>
                                <span v-if="el.locked" title="مقفل">🔒</span>
                                <span v-if="el.pages !== 'all'" class="text-[10px] text-amber-600">{{ { first: 'أولى', last: 'أخيرة', not_first: '≠أولى', not_last: '≠أخيرة' }[el.pages] }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </aside>

            <!-- Canvas -->
            <main ref="scroller" class="flex-1 overflow-auto" dir="ltr" @pointerdown.self="selected = []">
                <div class="p-8 min-w-max" @pointerdown.self="selected = []">
                    <div class="mx-auto relative" :style="canvasSize">
                        <div ref="pageEl" class="absolute top-0 left-0 bg-white shadow-xl" :style="{ width: `${page.width}mm`, height: `${page.height}mm`, transform: `scale(${zoom})`, transformOrigin: 'top left', ...gridStyle }" @pointerdown.self="selected = []">
                            <img v-if="page.background.src" :src="imageUrl(page.background.src)" alt="" class="absolute inset-0 w-full h-full pointer-events-none select-none" :style="{ opacity: page.background.opacity }" draggable="false" />
                            <FreeformElement v-for="el in layout.elements" :key="el.id" :data-el="el.id" :data-type="el.type" :el="el" :fields="fields" :rows="rows" :labels="labels" :page-font="page.font" :logo="logo" :qr="qrUrl" :image-url="imageUrl"
                                :class="['select-none', el.locked ? 'cursor-default' : 'cursor-move', selected.includes(el.id) ? '' : 'hover:outline hover:outline-1 hover:outline-indigo-300']"
                                :style="{ opacity: visible(el) ? 1 : 0.2 }" @pointerdown.stop="onElementDown($event, el)" />
                            <!-- Selection -->
                            <div v-for="el in sel" :key="`s-${el.id}`" class="absolute pointer-events-none" :style="{ left: `${el.x}mm`, top: `${el.y}mm`, width: `${el.w}mm`, height: `${el.h}mm`, outline: `${1.5 / zoom}px solid #4f46e5` }">
                                <template v-if="single && !el.locked">
                                    <span v-for="d in handles(el)" :key="d" class="absolute w-2 h-2 bg-white border border-indigo-600 pointer-events-auto" :style="handleStyle(d)" @pointerdown.stop.prevent="onHandleDown($event, el, d)"></span>
                                </template>
                                <span v-if="single" class="absolute -top-5 left-0 whitespace-nowrap bg-indigo-600 text-white rounded px-1 font-mono" :style="{ fontSize: `${10 / zoom}px`, transform: 'translateY(-2px)' }">{{ el.x }} × {{ el.y }} — {{ el.w }} × {{ el.h }} مم</span>
                            </div>
                            <!-- Alignment guides -->
                            <div v-for="(g, i) in guides" :key="`g${i}`" class="absolute pointer-events-none bg-rose-500" :style="g.v ? { left: `${g.at}mm`, top: 0, width: `${1 / zoom}px`, height: '100%' } : { top: `${g.at}mm`, left: 0, height: `${1 / zoom}px`, width: '100%' }"></div>
                        </div>
                    </div>
                </div>
            </main>

            <!-- Properties (left) -->
            <aside class="w-80 shrink-0 bg-white border-r border-slate-200 overflow-y-auto p-4">
                <ElementProps v-if="single" :key="single.id" :el="single" :catalogue="catalogue" :fonts="fonts" :upload="upload" :image-url="imageUrl"
                    @duplicate="duplicate" @remove="removeSelected" @front="toFront" @back="toBack" />

                <div v-else-if="sel.length > 1" class="space-y-3 text-xs">
                    <p class="font-black">{{ sel.length }} عناصر محددة</p>
                    <div class="grid grid-cols-3 gap-2">
                        <button v-for="[k, l] in [['right', 'محاذاة يمين'], ['hcenter', 'توسيط أفقي'], ['left', 'محاذاة يسار'], ['top', 'محاذاة أعلى'], ['vcenter', 'توسيط رأسي'], ['bottom', 'محاذاة أسفل'], ['width', 'نفس العرض'], ['height', 'نفس الارتفاع']]" :key="k" type="button" @click="align(k)" class="py-2 rounded-lg border border-slate-200 font-bold hover:bg-slate-50">{{ l }}</button>
                    </div>
                    <div class="flex gap-2"><button type="button" @click="duplicate" class="flex-1 py-2 rounded-xl border border-slate-200 font-bold">نسخ</button><button type="button" @click="removeSelected" class="flex-1 py-2 rounded-xl border border-rose-200 text-rose-600 font-bold">حذف</button></div>
                </div>

                <div v-else class="space-y-4 text-xs">
                    <section class="space-y-2">
                        <h3 class="font-black">الصفحة</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="font-bold text-slate-500">المقاس
                                <select :value="page.size" @change="setPaper($event.target.value)" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option v-for="(d, k) in papers" :key="k" :value="k">{{ k }} ({{ d[0] }}×{{ d[1] }})</option><option value="custom">مقاس مخصص</option></select>
                            </label>
                            <label class="font-bold text-slate-500">الاتجاه
                                <select :value="page.orientation" @change="setOrientation($event.target.value)" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option value="portrait">طولي</option><option value="landscape">عرضي</option></select>
                            </label>
                            <template v-if="page.size === 'custom'">
                                <label class="font-bold text-slate-500">العرض (مم)<input v-model.number="page.width" type="number" min="50" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                                <label class="font-bold text-slate-500">الطول (مم)<input v-model.number="page.height" type="number" min="50" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                            </template>
                            <label class="font-bold text-slate-500 col-span-2">خط الصفحة
                                <select v-model="page.font" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option v-for="f in fonts" :key="f.key" :value="f.key">{{ f.label }}</option></select>
                            </label>
                        </div>
                    </section>
                    <section class="space-y-2">
                        <h3 class="font-black">الشبكة والمحاذاة</h3>
                        <label class="flex items-center gap-2"><input v-model="showGrid" type="checkbox" class="rounded border-slate-300" /> إظهار الشبكة</label>
                        <label class="flex items-center gap-2"><input v-model="snap" type="checkbox" class="rounded border-slate-300" /> الالتصاق بالشبكة والعناصر الأخرى</label>
                        <label class="flex items-center gap-2 text-slate-500">خطوة الشبكة (مم)<input v-model.number="layout.grid" type="number" min="0.5" max="10" step="0.5" class="w-20 rounded-lg border-slate-200 text-xs" /></label>
                    </section>
                    <section class="space-y-2">
                        <h3 class="font-black">صورة خلفية</h3>
                        <p class="text-slate-400">ارفع صورة ممسوحة لنموذجك الورقي لتضع الحقول فوقها بدقة، أو ورقاً مطبوعاً مسبقاً (ترويسة).</p>
                        <label class="block py-2 rounded-xl border border-dashed border-slate-300 text-center font-bold cursor-pointer hover:bg-slate-50">{{ page.background.src ? 'تغيير الصورة' : 'رفع صورة' }}<input type="file" accept="image/png,image/jpeg" class="hidden" @change="uploadBackground" /></label>
                        <template v-if="page.background.src">
                            <label class="flex items-center gap-2"><input v-model="page.background.print" type="checkbox" class="rounded border-slate-300" /> تُطبع مع المستند (وإلا فهي دليل للتصميم فقط)</label>
                            <label class="flex items-center gap-2 text-slate-500">الشفافية<input v-model.number="page.background.opacity" type="range" min="0.05" max="1" step="0.05" class="flex-1" /></label>
                            <button type="button" @click="page.background.src = null" class="text-rose-600 font-bold">إزالة الصورة</button>
                        </template>
                    </section>
                    <section class="space-y-2">
                        <h3 class="font-black">بيانات المعاينة</h3>
                        <select v-model="documentId" @change="loadDocument" class="w-full rounded-lg border-slate-200 text-xs" aria-label="مستند المعاينة">
                            <option value="">{{ documents.length ? 'آخر مستند' : 'بيانات تجريبية' }}</option>
                            <option v-for="d in documents" :key="d.id" :value="d.id">{{ d.number }}</option>
                        </select>
                        <div class="flex gap-2" role="radiogroup" aria-label="الصفحة المعروضة">
                            <button type="button" role="radio" :aria-checked="previewPage === 'first'" @click="previewPage = 'first'" :class="['flex-1 py-1.5 rounded-lg border font-bold', previewPage === 'first' ? 'bg-slate-800 text-white' : 'border-slate-200']">الصفحة الأولى</button>
                            <button type="button" role="radio" :aria-checked="previewPage === 'last'" @click="previewPage = 'last'" :class="['flex-1 py-1.5 rounded-lg border font-bold', previewPage === 'last' ? 'bg-slate-800 text-white' : 'border-slate-200']">الأخيرة ({{ chunks.length }})</button>
                        </div>
                        <label class="flex items-center gap-2"><input v-model="showFields" type="checkbox" class="rounded border-slate-300" /> عرض أسماء الحقول بدل القيم</label>
                    </section>
                    <section class="flex flex-col gap-2 pt-2 border-t border-slate-100">
                        <button type="button" @click="startOver" class="py-2 rounded-xl border border-slate-200 font-bold">البدء من التصميم الجاهز</button>
                        <button type="button" @click="clearAll" class="py-2 rounded-xl border border-rose-200 text-rose-600 font-bold">صفحة فارغة</button>
                    </section>
                    <p class="text-slate-400 leading-5">اختصارات: Ctrl+S حفظ، Ctrl+Z تراجع، Ctrl+D نسخ العنصر، Delete حذف، الأسهم تحريك 0.5 مم (مع Shift: 5 مم).</p>
                </div>
            </aside>
        </div>
    </div>
</template>

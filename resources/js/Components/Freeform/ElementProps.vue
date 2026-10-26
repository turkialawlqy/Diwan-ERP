<script setup>
import { computed, ref } from 'vue';
import FieldPicker from './FieldPicker.vue';
import { PAGE_RULES, rowsPerPage } from './freeform';

/** Properties of the selected element; edits mutate it in place. */
const props = defineProps({
    el: { type: Object, required: true },
    catalogue: { type: Object, required: true },
    fonts: { type: Array, required: true },
    upload: { type: Function, required: true },
    imageUrl: { type: Function, required: true },
});
const emit = defineEmits(['duplicate', 'remove', 'front', 'back']);
const s = computed(() => props.el.style);
const content = ref(null);
const showFields = ref(false);
const uploading = ref(false);
const columnsCatalogue = computed(() => props.catalogue.columns);

const insertField = (key) => {
    const token = `{{${key}}}`;
    const ta = content.value;
    const text = props.el.content || '';
    if (ta) {
        const start = ta.selectionStart ?? text.length;
        const end = ta.selectionEnd ?? text.length;
        props.el.content = text.slice(0, start) + token + text.slice(end);
        requestAnimationFrame(() => { ta.focus(); ta.selectionStart = ta.selectionEnd = start + token.length; });
    } else {
        props.el.content = text + token;
    }
    showFields.value = false;
};
const num = (obj, key, v) => { const n = Number(v); if (!Number.isNaN(n)) obj[key] = Math.round(n * 100) / 100; };
const onImage = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    uploading.value = true;
    try { props.el.src = await props.upload(file); } finally { uploading.value = false; e.target.value = ''; }
};
const addColumn = () => {
    const c = columnsCatalogue.value.find((x) => !props.el.columns.some((y) => y.key === x.key)) || columnsCatalogue.value[0];
    props.el.columns.push({ key: c.key, label: c.label, label_en: c.label_en, width: 10, align: c.align });
};
const setColumnKey = (col, key) => {
    const c = columnsCatalogue.value.find((x) => x.key === key);
    Object.assign(col, { key, label: c.label, label_en: c.label_en, align: c.align });
};
const moveColumn = (i, d) => {
    const j = i + d;
    if (j < 0 || j >= props.el.columns.length) return;
    const cols = props.el.columns;
    [cols[i], cols[j]] = [cols[j], cols[i]];
};
const types = { text: 'نص / حقل', box: 'مستطيل', line: 'خط', image: 'صورة', qr: 'رمز QR', table: 'جدول الأصناف' };
const boxed = computed(() => props.el.type !== 'line');
</script>

<template>
    <div class="space-y-4 text-xs">
        <div class="flex items-center justify-between">
            <span class="px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-black">{{ types[el.type] }}</span>
            <div class="flex gap-1">
                <button type="button" @click="emit('front')" title="إلى الأمام" class="px-2 py-1 rounded-lg border border-slate-200">⬆ للأمام</button>
                <button type="button" @click="emit('back')" title="إلى الخلف" class="px-2 py-1 rounded-lg border border-slate-200">⬇ للخلف</button>
            </div>
        </div>

        <section class="grid grid-cols-4 gap-2">
            <label v-for="k in ['x', 'y', 'w', 'h']" :key="k" class="font-bold text-slate-500">{{ { x: 'أفقي', y: 'رأسي', w: 'العرض', h: 'الارتفاع' }[k] }} (مم)
                <input :value="el[k]" @change="num(el, k, $event.target.value)" type="number" step="0.5" class="mt-1 w-full rounded-lg border-slate-200 text-xs font-mono px-1" />
            </label>
        </section>
        <div class="grid grid-cols-2 gap-2">
            <label class="font-bold text-slate-500">يظهر في
                <select v-model="el.pages" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option v-for="(l, k) in PAGE_RULES" :key="k" :value="k">{{ l }}</option></select>
            </label>
            <label class="font-bold text-slate-500">اسم العنصر
                <input v-model="el.name" class="mt-1 w-full rounded-lg border-slate-200 text-xs" />
            </label>
        </div>
        <label class="flex items-center gap-2 font-bold text-slate-600"><input v-model="el.locked" type="checkbox" class="rounded border-slate-300" /> قفل (منع التحريك بالخطأ)</label>

        <!-- Text -->
        <section v-if="el.type === 'text'" class="space-y-2">
            <div class="flex items-center justify-between">
                <span class="font-black text-slate-700">المحتوى</span>
                <button type="button" @click="showFields = !showFields" class="px-2 py-1 rounded-lg bg-indigo-600 text-white font-bold">+ إدراج حقل</button>
            </div>
            <div v-if="showFields" class="p-2 rounded-xl border border-indigo-200 bg-indigo-50/40"><FieldPicker :catalogue="catalogue" compact @pick="insertField" /></div>
            <textarea ref="content" v-model="el.content" rows="4" dir="rtl" aria-label="محتوى النص" class="w-full rounded-lg border-slate-200 text-xs font-mono"></textarea>
            <p class="text-slate-400">الحقول تُكتب بين {{ '{' + '{' }} و {{ '}' + '}' }} وتُستبدل بالقيمة عند الطباعة. اكتب أي نص ثابت حولها.</p>
        </section>

        <!-- Image -->
        <section v-if="el.type === 'image'" class="space-y-2">
            <span class="font-black text-slate-700">الصورة</span>
            <div class="flex gap-2">
                <button type="button" @click="el.src = 'logo'" :class="['flex-1 px-2 py-1.5 rounded-lg border font-bold', el.src === 'logo' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200']">شعار الشركة</button>
                <label class="flex-1 px-2 py-1.5 rounded-lg border border-slate-200 font-bold text-center cursor-pointer hover:bg-slate-50">{{ uploading ? 'جارٍ الرفع...' : 'رفع صورة' }}<input type="file" accept="image/png,image/jpeg,image/gif" class="hidden" @change="onImage" /></label>
            </div>
            <label class="font-bold text-slate-500 block">الملاءمة
                <select v-model="el.fit" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option value="contain">داخل الإطار مع الحفاظ على النسبة</option><option value="stretch">تمديد لملء الإطار</option></select>
            </label>
        </section>

        <!-- Line -->
        <section v-if="el.type === 'line'" class="grid grid-cols-2 gap-2">
            <label class="font-bold text-slate-500">الاتجاه
                <select v-model="el.orientation" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option value="h">أفقي</option><option value="v">رأسي</option></select>
            </label>
            <label class="font-bold text-slate-500">السُمك (مم)<input :value="s.border_width" @change="num(s, 'border_width', $event.target.value)" type="number" step="0.1" min="0.05" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
            <label class="font-bold text-slate-500">النمط
                <select v-model="s.border_style" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option value="solid">متصل</option><option value="dashed">متقطع</option><option value="dotted">منقط</option><option value="double">مزدوج</option></select>
            </label>
            <label class="font-bold text-slate-500">اللون<input v-model="s.border_color" type="color" class="mt-1 w-full h-8 rounded-lg border-slate-200" /></label>
        </section>

        <!-- Table -->
        <section v-if="el.type === 'table'" class="space-y-2">
            <div class="flex items-center justify-between"><span class="font-black text-slate-700">الأعمدة</span><span class="text-slate-400">{{ rowsPerPage(el) }} بند في كل صفحة</span></div>
            <div v-for="(c, i) in el.columns" :key="i" class="p-2 rounded-xl border border-slate-200 space-y-1.5">
                <div class="flex gap-1">
                    <select :value="c.key" @change="setColumnKey(c, $event.target.value)" class="flex-1 rounded-lg border-slate-200 text-xs" :aria-label="`حقل العمود ${i + 1}`"><option v-for="x in columnsCatalogue" :key="x.key" :value="x.key">{{ x.label }}</option></select>
                    <button type="button" @click="moveColumn(i, -1)" class="px-1.5 rounded-lg border border-slate-200" aria-label="تقديم">→</button>
                    <button type="button" @click="moveColumn(i, 1)" class="px-1.5 rounded-lg border border-slate-200" aria-label="تأخير">←</button>
                    <button type="button" @click="el.columns.splice(i, 1)" class="px-1.5 rounded-lg border border-rose-200 text-rose-600" aria-label="حذف العمود">✕</button>
                </div>
                <div class="grid grid-cols-4 gap-1">
                    <input v-model="c.label" placeholder="العنوان" class="col-span-2 rounded-lg border-slate-200 text-xs" :aria-label="`عنوان العمود ${i + 1}`" />
                    <input v-model="c.label_en" placeholder="English" dir="ltr" class="col-span-2 rounded-lg border-slate-200 text-xs" :aria-label="`العنوان الإنجليزي ${i + 1}`" />
                    <label class="col-span-2 text-slate-500 flex items-center gap-1">العرض<input :value="c.width" @change="num(c, 'width', $event.target.value)" type="number" min="1" class="w-full rounded-lg border-slate-200 text-xs" /></label>
                    <select v-model="c.align" class="col-span-2 rounded-lg border-slate-200 text-xs" aria-label="محاذاة العمود"><option value="right">يمين</option><option value="center">وسط</option><option value="left">يسار</option></select>
                </div>
            </div>
            <button type="button" @click="addColumn" class="w-full py-1.5 rounded-lg border border-dashed border-slate-300 font-bold">+ عمود</button>
            <div class="grid grid-cols-2 gap-2">
                <label class="font-bold text-slate-500">ارتفاع العنوان (مم)<input :value="el.header_height" @change="num(el, 'header_height', $event.target.value)" type="number" step="0.5" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                <label class="font-bold text-slate-500">ارتفاع السطر (مم)<input :value="el.row_height" @change="num(el, 'row_height', $event.target.value)" type="number" step="0.5" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                <label class="font-bold text-slate-500">خلفية العنوان<input :value="el.header_bg || '#ffffff'" @input="el.header_bg = $event.target.value" type="color" class="mt-1 w-full h-8 rounded-lg" /></label>
                <label class="font-bold text-slate-500">لون نص العنوان<input v-model="el.header_color" type="color" class="mt-1 w-full h-8 rounded-lg" /></label>
                <label class="font-bold text-slate-500">حجم خط العنوان<input :value="el.header_size" @change="num(el, 'header_size', $event.target.value)" type="number" step="0.5" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                <label class="font-bold text-slate-500">لون خطوط الجدول<input v-model="el.grid_color" type="color" class="mt-1 w-full h-8 rounded-lg" /></label>
                <label class="font-bold text-slate-500">سمك خطوط الجدول<input :value="el.grid_width" @change="num(el, 'grid_width', $event.target.value)" type="number" step="0.05" min="0" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                <label class="font-bold text-slate-500">تظليل الأسطر بالتناوب<input :value="el.stripe || '#ffffff'" @input="el.stripe = $event.target.value" type="color" class="mt-1 w-full h-8 rounded-lg" /></label>
            </div>
            <div class="flex flex-wrap gap-3">
                <label class="flex items-center gap-1"><input v-model="el.col_lines" type="checkbox" class="rounded border-slate-300" /> خطوط بين الأعمدة</label>
                <label class="flex items-center gap-1"><input v-model="el.row_lines" type="checkbox" class="rounded border-slate-300" /> خطوط بين الأسطر</label>
                <button v-if="el.stripe" type="button" @click="el.stripe = null" class="text-rose-600">بدون تظليل</button>
                <button v-if="el.header_bg" type="button" @click="el.header_bg = null" class="text-rose-600">بدون خلفية للعنوان</button>
            </div>
            <p class="text-slate-400">إذا زادت البنود عن مساحة الجدول تُطبع صفحة جديدة بنفس التصميم وتكمل البنود فيها.</p>
        </section>

        <!-- Font -->
        <section v-if="['text', 'table'].includes(el.type)" class="space-y-2">
            <span class="font-black text-slate-700">الخط</span>
            <div class="grid grid-cols-2 gap-2">
                <select v-model="s.font" class="rounded-lg border-slate-200 text-xs" aria-label="نوع الخط"><option :value="null">خط الصفحة</option><option v-for="f in fonts" :key="f.key" :value="f.key">{{ f.label }}</option></select>
                <label class="flex items-center gap-1 text-slate-500">الحجم<input :value="s.size" @change="num(s, 'size', $event.target.value)" type="number" step="0.5" min="4" class="w-full rounded-lg border-slate-200 text-xs" aria-label="حجم الخط" /></label>
            </div>
            <div class="flex flex-wrap items-center gap-1">
                <button type="button" @click="s.bold = !s.bold" :class="['w-8 h-8 rounded-lg border font-black', s.bold ? 'bg-slate-800 text-white' : 'border-slate-200']" aria-label="عريض">B</button>
                <button type="button" @click="s.italic = !s.italic" :class="['w-8 h-8 rounded-lg border italic', s.italic ? 'bg-slate-800 text-white' : 'border-slate-200']" aria-label="مائل">I</button>
                <button type="button" @click="s.underline = !s.underline" :class="['w-8 h-8 rounded-lg border underline', s.underline ? 'bg-slate-800 text-white' : 'border-slate-200']" aria-label="تسطير">U</button>
                <input v-model="s.color" type="color" class="w-10 h-8 rounded-lg" aria-label="لون النص" />
                <span class="mx-1 text-slate-300">|</span>
                <button v-for="[a, l] in [['right', 'يمين'], ['center', 'وسط'], ['left', 'يسار'], ['justify', 'ضبط']]" :key="a" type="button" @click="s.align = a" :class="['px-2 h-8 rounded-lg border', s.align === a ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200']">{{ l }}</button>
            </div>
            <div v-if="el.type === 'text'" class="grid grid-cols-3 gap-2">
                <select v-model="s.valign" class="rounded-lg border-slate-200 text-xs" aria-label="المحاذاة الرأسية"><option value="top">أعلى</option><option value="middle">منتصف</option><option value="bottom">أسفل</option></select>
                <select v-model="s.dir" class="rounded-lg border-slate-200 text-xs" aria-label="اتجاه النص"><option value="rtl">عربي ←</option><option value="ltr">→ English</option></select>
                <label class="flex items-center gap-1 text-slate-500">تباعد<input :value="s.line_height" @change="num(s, 'line_height', $event.target.value)" type="number" step="0.1" min="0.8" class="w-full rounded-lg border-slate-200 text-xs" aria-label="تباعد الأسطر" /></label>
            </div>
            <select v-else v-model="s.dir" class="w-full rounded-lg border-slate-200 text-xs" aria-label="اتجاه الجدول"><option value="rtl">الجدول من اليمين لليسار</option><option value="ltr">الجدول من اليسار لليمين</option></select>
        </section>

        <!-- Box: border, background -->
        <section v-if="boxed" class="space-y-2">
            <span class="font-black text-slate-700">الإطار والخلفية</span>
            <div class="grid grid-cols-3 gap-2">
                <label class="text-slate-500">السُمك<input :value="s.border_width" @change="num(s, 'border_width', $event.target.value)" type="number" step="0.1" min="0" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                <label class="text-slate-500">النمط<select v-model="s.border_style" class="mt-1 w-full rounded-lg border-slate-200 text-xs"><option value="solid">متصل</option><option value="dashed">متقطع</option><option value="dotted">منقط</option><option value="double">مزدوج</option></select></label>
                <label class="text-slate-500">اللون<input v-model="s.border_color" type="color" class="mt-1 w-full h-8 rounded-lg" /></label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-slate-500">الجوانب:</span>
                <label v-for="[k, l] in [['top', 'أعلى'], ['right', 'يمين'], ['bottom', 'أسفل'], ['left', 'يسار']]" :key="k" class="flex items-center gap-1"><input v-model="s.border_sides[k]" type="checkbox" class="rounded border-slate-300" /> {{ l }}</label>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <label class="text-slate-500">الخلفية<input :value="s.bg || '#ffffff'" @input="s.bg = $event.target.value" type="color" class="mt-1 w-full h-8 rounded-lg" /></label>
                <label class="text-slate-500">تدوير الزوايا<input :value="s.radius" @change="num(s, 'radius', $event.target.value)" type="number" step="0.5" min="0" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
                <label v-if="el.type === 'text'" class="text-slate-500">الهامش الداخلي<input :value="s.padding" @change="num(s, 'padding', $event.target.value)" type="number" step="0.5" min="0" class="mt-1 w-full rounded-lg border-slate-200 text-xs" /></label>
            </div>
            <button v-if="s.bg" type="button" @click="s.bg = null" class="text-rose-600">إزالة الخلفية</button>
        </section>

        <div class="flex gap-2 pt-2 border-t border-slate-100">
            <button type="button" @click="emit('duplicate')" class="flex-1 py-2 rounded-xl border border-slate-200 font-bold">نسخ العنصر</button>
            <button type="button" @click="emit('remove')" class="flex-1 py-2 rounded-xl border border-rose-200 text-rose-600 font-bold">حذف</button>
        </div>
    </div>
</template>

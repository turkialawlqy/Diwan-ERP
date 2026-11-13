<script setup>
import { useI18n } from 'vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { CalendarDaysIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { hijri } from '@/utils/format';
import { toLatinDigits } from '@/utils/latinDigits';
const { t } = useI18n();

/**
 * The app's date field: a typed YYYY-MM-DD box with a calendar popup in Arabic
 * (weeks start on Saturday, Western digits, month and year pickers, today /
 * clear, Hijri date of the selection). v-model is always 'YYYY-MM-DD' or ''.
 * Use it instead of <input type="date">; type "month" picks YYYY-MM.
 * The box is never wider than the space it is given: in a narrow grid cell or a
 * crowded row it shrinks instead of pushing the page sideways.
 */
defineOptions({ inheritAttrs: false });
const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    type: { type: String, default: 'date' }, // date | month
});
const emit = defineEmits(['update:modelValue', 'change']);

const months = computed(() => ['cmp.month_1', 'cmp.month_2', 'cmp.month_3', 'cmp.month_4', 'cmp.month_5', 'cmp.month_6', 'cmp.month_7', 'cmp.month_8', 'cmp.month_9', 'cmp.month_10', 'cmp.month_11', 'cmp.month_12'].map((k) => t(k)));
const weekdays = computed(() => ['cmp.wd_1', 'cmp.wd_2', 'cmp.wd_3', 'cmp.wd_4', 'cmp.wd_5', 'cmp.wd_6', 'cmp.wd_7'].map((k) => t(k))); // Saturday first
const isMonth = computed(() => props.type === 'month');
const pad = (n) => String(n).padStart(2, '0');
const ymd = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
const todayStr = () => { const t = new Date(); return ymd(t.getFullYear(), t.getMonth(), t.getDate()); };
const parse = (s) => {
    const m = String(s || '').match(isMonth.value ? /^(\d{4})-(\d{2})$/ : /^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return null;
    const [y, mo, d] = [Number(m[1]), Number(m[2]) - 1, Number(m[3] ?? 1)];
    const dt = new Date(y, mo, d);
    return dt.getFullYear() === y && dt.getMonth() === mo && dt.getDate() === d ? { y, m: mo, d } : null;
};

const root = ref(null);
const input = ref(null);
const panel = ref(null);
const open = ref(false);
const text = ref(props.modelValue || '');
const view = ref({ y: new Date().getFullYear(), m: new Date().getMonth() });
const pos = ref({ top: 0, left: 0 });

watch(() => props.modelValue, (v) => { text.value = v || ''; });

const selected = computed(() => parse(props.modelValue));
const inRange = (s) => (!props.min || s >= props.min) && (!props.max || s <= props.max);
const yearOptions = computed(() => {
    const base = new Date().getFullYear();
    const from = props.min ? Number(props.min.slice(0, 4)) : Math.min(base - 15, view.value.y);
    const to = props.max ? Number(props.max.slice(0, 4)) : Math.max(base + 10, view.value.y);
    return Array.from({ length: to - from + 1 }, (_, i) => from + i);
});
const cells = computed(() => {
    const first = new Date(view.value.y, view.value.m, 1);
    const lead = (first.getDay() + 1) % 7; // Saturday = 0
    const days = new Date(view.value.y, view.value.m + 1, 0).getDate();
    const out = Array.from({ length: lead }, () => null);
    for (let d = 1; d <= days; d++) out.push({ d, s: ymd(view.value.y, view.value.m, d) });
    return out;
});
const hijriText = computed(() => (selected.value && !isMonth.value ? hijri(props.modelValue) : ''));

const place = () => {
    const r = input.value?.getBoundingClientRect();
    if (!r) return;
    const height = panel.value?.offsetHeight || 330;
    const width = panel.value?.offsetWidth || 288;
    const below = window.innerHeight - r.bottom;
    pos.value = {
        top: below < height + 8 && r.top > height ? r.top - height - 4 : r.bottom + 4,
        left: Math.max(8, Math.min(window.innerWidth - width - 8, document.dir === 'rtl' ? r.right - width : r.left)),
    };
};
const show = async () => {
    if (props.disabled) return;
    const s = selected.value;
    view.value = s ? { y: s.y, m: s.m } : { y: new Date().getFullYear(), m: new Date().getMonth() };
    open.value = true;
    await nextTick();
    place();
};
const close = () => { open.value = false; text.value = props.modelValue || ''; };
// `change` carries an event-like object so existing handlers that read $event.target.value keep working.
const commit = (value) => { emit('update:modelValue', value); emit('change', { target: { value } }); };
const pick = (s) => { if (!inRange(s)) return; commit(s); open.value = false; };
const pickMonth = (m) => { const s = `${view.value.y}-${pad(m + 1)}`; if (!isMonth.value) return; commit(s); open.value = false; };
const clear = () => { commit(''); open.value = false; };
const today = () => pick(isMonth.value ? todayStr().slice(0, 7) : todayStr());
const step = (delta) => {
    const d = new Date(view.value.y, view.value.m + delta, 1);
    view.value = { y: d.getFullYear(), m: d.getMonth() };
};

const onInput = (e) => {
    const cleaned = toLatinDigits(e.target.value).replace(/[^0-9-]/g, '');
    if (cleaned !== e.target.value) e.target.value = cleaned;
    text.value = cleaned;
    const p = parse(cleaned);
    if (p && inRange(cleaned)) {
        commit(cleaned);
        view.value = { y: p.y, m: p.m };
    }
    if (cleaned === '' && !props.required) commit('');
};
const onBlurFix = () => { if (!open.value) text.value = props.modelValue || ''; };

const outside = (e) => {
    if (!open.value) return;
    if (root.value?.contains(e.target) || panel.value?.contains(e.target)) return;
    close();
};
const onScroll = (e) => { if (open.value && !panel.value?.contains(e.target)) place(); };
onMounted(() => {
    document.addEventListener('mousedown', outside);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', place);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', outside);
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', place);
});
</script>

<template>
    <div ref="root" class="relative min-w-0" data-date-picker>
        <input
            ref="input" v-bind="$attrs" :value="text" type="text" inputmode="numeric" autocomplete="off" dir="ltr"
            :placeholder="isMonth ? 'YYYY-MM' : 'YYYY-MM-DD'" :required="required" :disabled="disabled" :maxlength="isMonth ? 7 : 10"
            class="ps-9 text-start font-mono max-w-full"
            @focus="show" @click="show" @input="onInput" @blur="onBlurFix" @keydown.esc="close" @keydown.enter="open = false"
        />
        <span class="absolute inset-y-0 start-2 flex items-center text-slate-400 pointer-events-none"><CalendarDaysIcon class="w-4 h-4" /></span>
        <Teleport to="body">
            <div v-if="open" ref="panel" :style="{ top: pos.top + 'px', left: pos.left + 'px' }" class="fixed z-[60] w-72 bg-white border border-slate-200 rounded-2xl shadow-xl p-3 text-xs" data-date-panel dir="rtl">
                <div class="flex items-center gap-1.5 mb-2">
                    <button type="button" class="p-1.5 rounded-lg hover:bg-slate-100" :aria-label="$t('cmp.prev')" @click="step(isMonth ? -12 : -1)"><ChevronRightIcon class="w-4 h-4" /></button>
                    <select v-if="!isMonth" v-model.number="view.m" class="flex-1 rounded-lg border-slate-200 text-xs py-1">
                        <option v-for="(name, i) in months" :key="i" :value="i">{{ name }}</option>
                    </select>
                    <select v-model.number="view.y" class="rounded-lg border-slate-200 text-xs py-1" :class="isMonth ? 'flex-1' : 'w-24'">
                        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button type="button" class="p-1.5 rounded-lg hover:bg-slate-100" :aria-label="$t('cmp.next')" @click="step(isMonth ? 12 : 1)"><ChevronLeftIcon class="w-4 h-4" /></button>
                </div>
                <div v-if="isMonth" class="grid grid-cols-3 gap-1.5">
                    <button v-for="(name, i) in months" :key="i" type="button" class="py-2 rounded-lg hover:bg-indigo-50" :class="selected && selected.y === view.y && selected.m === i ? 'bg-indigo-600 text-white hover:bg-indigo-600 font-bold' : 'text-slate-700'" @click="pickMonth(i)">{{ name }}</button>
                </div>
                <template v-else>
                    <div class="grid grid-cols-7 text-center text-[11px] text-slate-400 mb-1">
                        <span v-for="w in weekdays" :key="w" class="py-1 font-bold">{{ w }}</span>
                    </div>
                    <div class="grid grid-cols-7 gap-0.5 text-center font-mono">
                        <template v-for="(c, i) in cells" :key="i">
                            <span v-if="!c"></span>
                            <button v-else type="button" :disabled="!inRange(c.s)" class="py-1.5 rounded-lg disabled:text-slate-300 disabled:cursor-not-allowed"
                                :class="c.s === modelValue ? 'bg-indigo-600 text-white font-bold' : (c.s === todayStr() ? 'border border-indigo-300 text-indigo-700 hover:bg-indigo-50' : 'text-slate-700 hover:bg-indigo-50')" @click="pick(c.s)">{{ c.d }}</button>
                        </template>
                    </div>
                </template>
                <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                    <button type="button" class="px-2.5 py-1 rounded-lg text-indigo-700 font-bold hover:bg-indigo-50" @click="today">{{ isMonth ? $t('cmp.this_month') : $t('cmp.today') }}</button>
                    <span v-if="hijriText" class="text-[10px] text-slate-400">{{ hijriText }}</span>
                    <button v-if="!required" type="button" class="px-2.5 py-1 rounded-lg text-slate-500 hover:bg-slate-100" @click="clear">{{ $t('cmp.clear') }}</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

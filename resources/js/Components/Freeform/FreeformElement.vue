<script setup>
import { computed } from 'vue';
import { borderCss, fontCss, resolveText } from './freeform';

/**
 * One element drawn on the canvas, sized in millimetres exactly like the PDF
 * (border-box: the border and padding sit inside the element's box).
 */
const props = defineProps({
    el: { type: Object, required: true },
    fields: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    labels: { type: Object, default: null }, // field labels instead of values
    pageFont: { type: String, default: 'xbriyaz' },
    logo: { type: String, default: null },
    qr: { type: String, default: null },
    imageUrl: { type: Function, required: true },
});

const s = computed(() => props.el.style);
const pad = computed(() => (['table', 'image', 'qr'].includes(props.el.type) ? 0 : s.value.padding));

const boxStyle = computed(() => {
    const el = props.el;
    if (el.type === 'line') return { position: 'absolute', left: `${el.x}mm`, top: `${el.y}mm`, width: `${el.w}mm`, height: `${el.h}mm` };
    return {
        position: 'absolute', left: `${el.x}mm`, top: `${el.y}mm`, width: `${el.w}mm`, height: `${el.h}mm`, boxSizing: 'border-box',
        padding: `${pad.value}mm`, overflow: 'hidden', background: s.value.bg || 'transparent', borderRadius: `${s.value.radius}mm`, ...borderCss(s.value),
    };
});

const lineStyle = computed(() => {
    const el = props.el;
    const border = `${s.value.border_width}mm ${s.value.border_style} ${s.value.border_color}`;
    return el.orientation === 'v'
        ? { position: 'absolute', left: '50%', top: 0, height: '100%', width: 0, borderLeft: border }
        : { position: 'absolute', top: '50%', left: 0, width: '100%', height: 0, borderTop: border };
});

const textStyle = computed(() => ({
    display: 'flex', height: '100%', width: '100%',
    alignItems: { top: 'flex-start', middle: 'center', bottom: 'flex-end' }[s.value.valign],
    textAlign: s.value.align, ...fontCss(s.value, props.pageFont),
}));
const text = computed(() => resolveText(props.el.content, props.fields, props.labels));

const imgSrc = computed(() => (props.el.src === 'logo' ? props.logo : props.imageUrl(props.el.src)));

// Table geometry mirrors the PDF: header, fixed-height rows, filler down to the bottom.
const table = computed(() => {
    const el = props.el;
    const sum = el.columns.reduce((a, c) => a + Number(c.width), 0) || 1;
    const used = el.header_height + props.rows.length * el.row_height;
    return { sum, filler: Math.max(0, el.h - used - 0.3) };
});
const grid = computed(() => (props.el.grid_width > 0 ? `${props.el.grid_width}mm solid ${props.el.grid_color}` : 'none'));
const sep = (i) => (props.el.col_lines && i < props.el.columns.length - 1 ? { [s.value.dir === 'rtl' ? 'borderLeft' : 'borderRight']: grid.value } : {});
const cellLabel = (row, col) => (props.labels ? `‹${col.label}›` : row[col.key] ?? '');
</script>

<template>
    <div :style="boxStyle">
        <div v-if="el.type === 'line'" :style="lineStyle"></div>

        <div v-else-if="el.type === 'text'" :style="textStyle"><div style="width: 100%; white-space: pre-wrap; overflow-wrap: anywhere">{{ text }}</div></div>

        <div v-else-if="el.type === 'image'" class="w-full h-full flex items-center justify-center">
            <img v-if="imgSrc" :src="imgSrc" alt="" :style="{ width: '100%', height: '100%', objectFit: el.fit === 'stretch' ? 'fill' : 'contain' }" draggable="false" />
            <span v-else class="text-[9px] text-slate-400">{{ el.src === 'logo' ? 'لا يوجد شعار للشركة' : 'صورة' }}</span>
        </div>

        <div v-else-if="el.type === 'qr'" class="w-full h-full flex items-center justify-center">
            <img v-if="qr" :src="qr" alt="QR" :style="{ width: `${Math.min(el.w, el.h)}mm`, height: `${Math.min(el.w, el.h)}mm` }" draggable="false" />
        </div>

        <table v-else-if="el.type === 'table'" :dir="s.dir" :style="{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', ...{ fontFamily: `ff-${s.font || pageFont}, sans-serif`, fontSize: `${s.size}pt`, color: s.color, lineHeight: s.line_height } }">
            <colgroup><col v-for="c in el.columns" :key="c.key + c.label" :style="{ width: `${(c.width / table.sum) * 100}%` }" /></colgroup>
            <tr :style="{ background: el.header_bg || 'transparent' }">
                <td v-for="(c, i) in el.columns" :key="i" :style="{ height: `${el.header_height}mm`, padding: '0 0.8mm', textAlign: 'center', verticalAlign: 'middle', fontWeight: 'bold', fontSize: `${el.header_size}pt`, color: el.header_color, borderBottom: grid, ...sep(i) }">
                    {{ c.label }}<template v-if="c.label_en"><br />{{ c.label_en }}</template>
                </td>
            </tr>
            <tr v-for="(row, r) in rows" :key="r" :style="{ background: el.stripe && r % 2 ? el.stripe : 'transparent' }">
                <td v-for="(c, i) in el.columns" :key="i" :style="{ height: `${el.row_height}mm`, padding: '0 0.8mm', verticalAlign: 'middle', textAlign: c.align, whiteSpace: 'pre-line', overflow: 'hidden', borderBottom: el.row_lines ? grid : 'none', ...sep(i) }">{{ cellLabel(row, c) }}</td>
            </tr>
            <tr v-if="table.filler > 0.5"><td v-for="(c, i) in el.columns" :key="i" :style="{ height: `${table.filler}mm`, padding: 0, ...sep(i) }"></td></tr>
        </table>
    </div>
</template>

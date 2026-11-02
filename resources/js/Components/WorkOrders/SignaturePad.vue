<script setup>
import { onMounted, ref } from 'vue';

/** A drawing pad (mouse, touch or pen) that gives a PNG data URL through v-model. */
const props = defineProps({ modelValue: { type: String, default: '' }, height: { type: Number, default: 180 } });
const emit = defineEmits(['update:modelValue']);
const canvas = ref(null);
let ctx = null;
let drawing = false;
let drawn = false;

onMounted(() => {
    const c = canvas.value;
    const ratio = window.devicePixelRatio || 1;
    c.width = c.clientWidth * ratio;
    c.height = props.height * ratio;
    ctx = c.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';
});

const pos = (e) => { const r = canvas.value.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
const down = (e) => { drawing = true; canvas.value.setPointerCapture?.(e.pointerId); const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
const move = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); drawn = true; };
const up = () => { if (!drawing) return; drawing = false; if (drawn) emit('update:modelValue', canvas.value.toDataURL('image/png')); };
const clear = () => { ctx.clearRect(0, 0, canvas.value.width, canvas.value.height); drawn = false; emit('update:modelValue', ''); };
</script>

<template>
    <div data-signature>
        <canvas ref="canvas" :style="{ height: height + 'px' }" class="w-full rounded-xl border border-dashed border-slate-300 bg-white touch-none" data-signature-canvas
            @pointerdown.prevent="down" @pointermove.prevent="move" @pointerup="up" @pointerleave="up" @pointercancel="up"></canvas>
        <button type="button" class="mt-1 text-3xs font-bold text-rose-600" @click="clear" data-signature-clear>مسح التوقيع</button>
    </div>
</template>

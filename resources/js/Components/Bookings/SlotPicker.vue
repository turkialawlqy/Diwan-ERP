<script setup>
import { ref, watch } from 'vue';

/**
 * Free start times of a service on a day (staff and portal share it). It asks `url` for
 * {service, resource, date, ignore}; the chosen slot is v-model (the "HH:mm"), and `picked`
 * also gives the resource the slot belongs to (for "any resource").
 */
const props = defineProps({ modelValue: { type: String, default: '' }, url: { type: String, required: true }, service: [Number, String], resource: [Number, String], date: { type: String, default: '' }, ignore: Number });
const emit = defineEmits(['update:modelValue', 'picked']);
const slots = ref([]);
const loading = ref(false);
let seq = 0;

const load = async () => {
    slots.value = [];
    if (!props.service || !props.date) return;
    const mine = ++seq;
    loading.value = true;
    try {
        const q = new URLSearchParams({ service: props.service, date: props.date });
        if (props.resource) q.set('resource', props.resource);
        if (props.ignore) q.set('ignore', props.ignore);
        const res = await fetch(`${props.url}?${q}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const body = await res.json();
        if (mine === seq) slots.value = body.data || [];
    } catch { /* leave empty */ } finally {
        if (mine === seq) loading.value = false;
    }
    if (props.modelValue && !slots.value.some((s) => s.time === props.modelValue)) emit('update:modelValue', '');
};
watch(() => [props.service, props.resource, props.date, props.ignore], load, { immediate: true });
const pick = (s) => { emit('update:modelValue', s.time); emit('picked', s); };
</script>

<template>
    <div data-slots>
        <p v-if="!service || !date" class="text-3xs text-slate-400">اختر الخدمة والتاريخ لتظهر الأوقات المتاحة.</p>
        <p v-else-if="loading" class="text-3xs text-slate-400">جارٍ تحميل الأوقات…</p>
        <p v-else-if="!slots.length" class="text-3xs text-rose-600 font-bold" data-no-slots>لا أوقات متاحة في هذا اليوم.</p>
        <div v-else class="flex flex-wrap gap-1.5">
            <button v-for="s in slots" :key="s.time" type="button" :data-slot="s.time"
                class="px-3 py-1.5 rounded-lg border text-xs font-mono font-bold" :class="modelValue === s.time ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-200 text-slate-700 hover:border-indigo-300'"
                @click="pick(s)">{{ s.time }}</button>
        </div>
    </div>
</template>

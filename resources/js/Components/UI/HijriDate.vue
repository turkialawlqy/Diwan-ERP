<script setup>
import { computed } from 'vue';
import { date, hijri } from '@/utils/format';

/** Gregorian date with its Hijri (Umm al-Qura) equivalent underneath. */
const props = defineProps({ value: { type: [String, Date], default: null }, inline: { type: Boolean, default: false } });
const h = computed(() => hijri(props.value));
</script>

<template>
    <span v-if="value" :class="inline ? '' : 'inline-flex flex-col leading-tight'">
        <span class="font-mono">{{ date(value) }}</span>
        <span v-if="h" class="text-[10px] text-slate-400" :class="inline ? 'ms-1' : ''">{{ inline ? `(${h})` : h }}</span>
    </span>
    <span v-else>—</span>
</template>

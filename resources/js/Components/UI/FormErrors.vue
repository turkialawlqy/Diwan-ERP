<script setup>
import { computed } from 'vue';

/*
 * The errors of a form that no field of it shows: business rules (a closed period, a box of another
 * branch, "all branches" shown in the top bar...) and fields the form does not render. AppLayout lists
 * them in its banner, but a dialog's overlay hides the banner, so a dialog shows them itself.
 * `except` names the keys the dialog already shows next to their field; 'lines.*' covers 'lines.0.qty'...
 */
const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    except: { type: Array, default: () => [] },
});

const shown = (key) => props.except.some((k) => (k.endsWith('.*') ? key.startsWith(k.slice(0, -1)) : key === k));
const messages = computed(() => Object.entries(props.errors || {})
    .filter(([key]) => !shown(key))
    .flatMap(([, message]) => String(message).split('\n'))
    .filter((message, i, all) => message && all.indexOf(message) === i));
</script>

<template>
    <div v-if="messages.length" role="alert" class="rounded-xl bg-rose-50 border border-rose-200 p-3 text-xs font-bold text-rose-700 space-y-1" data-dialog-errors>
        <p v-for="message in messages" :key="message">{{ message }}</p>
    </div>
</template>

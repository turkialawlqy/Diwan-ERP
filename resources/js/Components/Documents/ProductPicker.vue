<script setup>
import { ref, watch } from 'vue';
import { useLookup } from '@/composables/useLookup';

/** Type-ahead picker for one product (bounded server lookup). */
const props = defineProps({
    modelValue: [Number, String, null],
    initial: { type: Object, default: null },
    placeholder: { type: String, default: 'ابحث باسم الصنف أو رمزه أو الباركود...' },
});
const emit = defineEmits(['update:modelValue', 'select']);

const selected = ref(props.initial);
const { term, results, status, search, reset, messages } = useLookup('lookup.products');

watch(() => props.initial, (v) => { selected.value = v; });
watch(() => props.modelValue, (v) => { if (!v) selected.value = null; });

const pick = (product) => {
    selected.value = product;
    reset();
    emit('update:modelValue', product.id);
    emit('select', product);
};
const clear = () => { selected.value = null; emit('update:modelValue', null); emit('select', null); };
</script>

<template>
    <div class="relative">
        <div v-if="selected" class="flex items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs">
            <span class="font-bold text-slate-800">{{ selected.name_ar }} <span v-if="selected.sku" class="text-slate-400 font-mono ms-2">{{ selected.sku }}</span></span>
            <button type="button" @click="clear" class="text-slate-400 hover:text-rose-600" aria-label="إزالة">✕</button>
        </div>
        <template v-else>
            <input v-model="term" @input="search" :placeholder="placeholder" class="w-full rounded-xl border-slate-200 text-xs" />
            <div v-if="results.length" class="absolute z-30 mt-1 w-full bg-white border rounded-xl shadow max-h-56 overflow-y-auto">
                <button v-for="p in results" :key="p.id" type="button" @mousedown.prevent="pick(p)" @keydown.enter.prevent="pick(p)" class="block w-full text-start px-3 py-2 text-xs hover:bg-indigo-50">
                    {{ p.name_ar }} <span class="text-slate-400 font-mono">{{ p.sku }}</span>
                </button>
            </div>
            <div v-else-if="status" class="absolute z-30 mt-1 w-full bg-white border rounded-xl shadow px-3 py-2 text-xs" :class="status === 'loading' || status === 'empty' ? 'text-slate-500' : 'text-rose-600'">{{ messages[status] }}</div>
        </template>
    </div>
</template>

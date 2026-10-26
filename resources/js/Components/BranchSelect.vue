<script setup>
import { computed } from 'vue';
import { BuildingStorefrontIcon } from '@heroicons/vue/24/outline';

/**
 * Branch of a new document, from the branches the user may work in
 * (BranchOptions::forUser). Each branch carries its tax profile; the page
 * reads it to follow the chosen branch's rate and currency.
 */
const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    branches: { type: Array, default: () => [] },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const selected = computed(() => props.branches.find((b) => Number(b.id) === Number(props.modelValue)));
const manyCountries = computed(() => new Set(props.branches.map((b) => b.tax?.country)).size > 1);
const label = (b) => (manyCountries.value && b.tax?.country_name ? `${b.name_ar} — ${b.tax.country_name}` : b.name_ar);
</script>

<template>
    <div>
        <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <BuildingStorefrontIcon class="w-4 h-4 text-indigo-500" /> الفرع <span class="text-rose-500">*</span>
        </label>
        <select
            :value="modelValue"
            @change="emit('update:modelValue', Number($event.target.value))"
            data-branch-select
            class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-bold"
        >
            <option v-for="b in branches" :key="b.id" :value="b.id">{{ label(b) }}</option>
        </select>
        <p v-if="selected?.tax" class="mt-1 text-[11px] text-slate-500">
            العملة {{ selected.tax.currency }} ·
            <template v-if="selected.tax.has_tax">{{ selected.tax.name }} {{ Number(selected.tax.rate) }}%</template>
            <template v-else>بدون ضريبة</template>
        </p>
        <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

/**
 * VAT category and ZATCA exemption reason of a product.
 */
const props = defineProps({
    category: { type: String, default: 'S' },
    code: { type: String, default: '' },
    categories: { type: Object, required: true },
    reasons: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:category', 'update:code', 'update:taxRate']);
const options = computed(() => props.reasons[props.category] || {});
const setCategory = (value) => {
    emit('update:category', value);
    emit('update:code', '');
    if (value !== 'S') emit('update:taxRate', 0);
};
</script>

<template>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label class="block text-xs font-bold text-slate-700">الفئة الضريبية
            <select :value="category" @change="setCategory($event.target.value)" class="mt-1 w-full rounded-xl border-slate-200 text-xs">
                <option v-for="(label, key) in categories" :key="key" :value="key">{{ key }} — {{ label }}</option>
            </select>
            <span v-if="errors.vat_category" class="block text-[11px] text-rose-600 mt-1">{{ errors.vat_category }}</span>
        </label>
        <label v-if="category !== 'S'" class="block text-xs font-bold text-slate-700">سبب الإعفاء / النسبة الصفرية (ZATCA)
            <select :value="code" @change="emit('update:code', $event.target.value)" class="mt-1 w-full rounded-xl border-slate-200 text-xs">
                <option value="">— السبب الافتراضي للمنشأة —</option>
                <option v-for="(r, key) in options" :key="key" :value="key">{{ key }} — {{ r.ar }}</option>
            </select>
            <span v-if="errors.vat_exemption_code" class="block text-[11px] text-rose-600 mt-1">{{ errors.vat_exemption_code }}</span>
        </label>
    </div>
</template>

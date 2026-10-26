<script setup>
import { computed, useId } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Document currency + exchange rate. Hidden unless the company defines more
 * than one currency. An empty rate means "the rate on the document date"
 * (resolved by the server from the dated rates).
 */
const code = defineModel('code', { default: '' });
const rate = defineModel('rate', { default: '' });
defineProps({ errors: { type: Object, default: () => ({}) } });

const page = usePage();
const base = computed(() => page.props.tenant?.currency || 'SAR');
const currencies = computed(() => page.props.currencies || []);
const selected = computed(() => currencies.value.find((c) => c.code === code.value));
const foreign = computed(() => code.value && code.value !== base.value);
const id = useId();
</script>

<template>
    <div v-if="currencies.length > 1" class="grid grid-cols-2 gap-2">
        <div>
            <label :for="`${id}-code`" class="block text-xs font-bold text-slate-700 mb-1">العملة</label>
            <select :id="`${id}-code`" v-model="code" class="w-full rounded-xl border-slate-200 text-xs" @change="rate = ''">
                <option value="">{{ base }} (الأساسية)</option>
                <option v-for="c in currencies.filter((c) => c.code !== base)" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name_ar }}</option>
            </select>
            <span v-if="errors.currency_code" class="block text-[11px] text-rose-600 mt-1">{{ errors.currency_code }}</span>
        </div>
        <div v-if="foreign">
            <label :for="`${id}-rate`" class="block text-xs font-bold text-slate-700 mb-1">سعر الصرف (1 {{ code }} = ؟ {{ base }})</label>
            <input :id="`${id}-rate`" v-model="rate" type="number" step="0.000001" min="0" dir="ltr" :placeholder="`تلقائي حسب التاريخ (${Number(selected?.exchange_rate || 0).toFixed(4)})`" class="w-full rounded-xl border-slate-200 text-xs font-mono" />
            <span v-if="errors.exchange_rate" class="block text-[11px] text-rose-600 mt-1">{{ errors.exchange_rate }}</span>
        </div>
    </div>
</template>

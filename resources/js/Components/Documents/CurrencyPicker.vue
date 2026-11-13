<script setup>
import { useI18n } from 'vue-i18n';
import { computed, onMounted, useId } from 'vue';
import { usePage } from '@inertiajs/vue3';
const { t } = useI18n();

/**
 * Document currency + exchange rate. Hidden unless the company defines more
 * than one currency. A new document starts in the active branch's currency
 * (a Saudi branch of a Yemeni company issues in SAR); the other currencies are
 * one pick away. An empty code means the company's base currency; an empty rate
 * means "the rate on the document date" (resolved by the server).
 */
const code = defineModel('code', { default: '' });
const rate = defineModel('rate', { default: '' });
defineProps({ errors: { type: Object, default: () => ({}) } });

const page = usePage();
const base = computed(() => page.props.tenant?.currency || 'SAR');
const branchCurrency = computed(() => page.props.tax?.currency || base.value);
const currencies = computed(() => page.props.currencies || []);
const selected = computed(() => currencies.value.find((c) => c.code === (code.value || base.value)));
const foreign = computed(() => code.value && code.value !== base.value);
const noRate = computed(() => foreign.value && !(Number(selected.value?.exchange_rate) > 0));
const id = useId();

const shown = computed({
    get: () => code.value || base.value,
    set: (value) => { code.value = value === base.value ? '' : value; rate.value = ''; },
});
const label = (c) => `${c.code} — ${c.name_ar}${c.code === branchCurrency.value ? ' ('+t('cmp.branch_currency')+')' : (c.code === base.value ? ' ('+t('cmp.base_currency')+')' : '')}`;

onMounted(() => {
    if (!code.value && currencies.value.length > 1 && branchCurrency.value !== base.value && currencies.value.some((c) => c.code === branchCurrency.value)) {
        code.value = branchCurrency.value;
    }
});
</script>

<template>
    <div v-if="currencies.length > 1" class="grid grid-cols-2 gap-2">
        <div>
            <label :for="`${id}-code`" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('cmp.currency') }}</label>
            <select :id="`${id}-code`" v-model="shown" class="w-full rounded-xl border-slate-200 text-xs">
                <option v-if="!currencies.some((c) => c.code === base)" :value="base">{{ base }} ({{ $t('cmp.base_currency') }})</option>
                <option v-for="c in currencies" :key="c.code" :value="c.code">{{ label(c) }}</option>
            </select>
            <span v-if="errors.currency_code" class="block text-[11px] text-rose-600 mt-1">{{ errors.currency_code }}</span>
        </div>
        <div v-if="foreign">
            <label :for="`${id}-rate`" class="block text-xs font-bold text-slate-700 mb-1">{{ $t('cmp.exchange_rate') }} (1 {{ code }} = ? {{ base }})</label>
            <input :id="`${id}-rate`" v-model.number="rate" type="text" inputmode="decimal" data-num step="0.000001" min="0" dir="ltr" :required="noRate" :placeholder="noRate ? $t('cmp.enter_rate') : `${$t('cmp.auto_by_date')} (${Number(selected?.exchange_rate || 0).toFixed(4)})`" class="w-full rounded-xl border-slate-200 text-xs font-mono" />
            <span v-if="noRate && !errors.exchange_rate" class="block text-[11px] text-amber-600 mt-1">{{ $t('cmp.no_exchange_rate_is_saved_for_th') }}</span>
            <span v-if="errors.exchange_rate" class="block text-[11px] text-rose-600 mt-1">{{ errors.exchange_rate }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { dimensionRequired } from '@/utils/dimensions';

/**
 * Cost centre and project of a voucher, marked required when the company policy
 * (Accounting → Account mapping) asks for it on the chosen account.
 */
const props = defineProps({
    account: { type: Object, default: null }, // { type, requires_cost_center }
    policy: { type: Object, default: () => ({}) },
    costCenters: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});
const costCenter = defineModel('costCenter', { default: '' });
const project = defineModel('project', { default: '' });
const needCc = computed(() => dimensionRequired(props.policy, 'cost_center', props.account));
const needProject = computed(() => dimensionRequired(props.policy, 'project', props.account));
</script>

<template>
    <div class="grid grid-cols-2 gap-3">
        <Field :label="'مركز التكلفة' + (needCc ? ' *' : '')" :error="errors.cost_center_id">
            <SelectCreate v-model="costCenter" :options="costCenters" entity="cost_center" :label="(c) => `${c.code} - ${c.name_ar}`" :placeholder="$t('cmp.none')" :required="needCc" :allow-empty="!needCc" />
        </Field>
        <Field :label="'المشروع' + (needProject ? ' *' : '')" :error="errors.project_id">
            <select v-model="project" :required="needProject" class="w-full rounded-xl border-slate-200 text-xs">
                <option value="">{{ $t('cmp.none') }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
        </Field>
    </div>
</template>

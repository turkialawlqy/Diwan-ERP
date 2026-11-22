<script setup>
import { useI18n } from 'vue-i18n';
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { tx } from '@/i18n';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { quickCreate } from '@/quickCreate';
const { t } = useI18n();

/**
 * Full create form for an entity, opened from "إنشاء «...»" in a dropdown.
 * Fields come from resources/js/quickCreate.js; it posts to the same store route
 * as the entity's own page and returns the new record through `created`.
 */
const props = defineProps({
    entity: { type: String, required: true },
    name: { type: String, default: '' },
    // Fields the form the dropdown sits in presets (an account made from the revenue form is a revenue account).
    defaults: { type: Object, default: null },
});
const emit = defineEmits(['close', 'created']);

const config = quickCreate[props.entity];
const fields = config.fields();
const form = reactive({});
const errors = ref({});
const saving = ref(false);
const lookups = reactive({});

const dayNames = computed(() => ([[6, t('z_resources_js_components_ui_quickcreatedialog.saturday')], [7, t('z_resources_js_components_ui_quickcreatedialog.sunday')], [1, t('z_resources_js_components_ui_quickcreatedialog.monday')], [2, t('z_resources_js_components_ui_quickcreatedialog.tuesday')], [3, t('z_resources_js_components_ui_quickcreatedialog.wednesday')], [4, t('z_resources_js_components_ui_quickcreatedialog.thursday')], [5, t('z_resources_js_components_ui_quickcreatedialog.friday')]]));

fields.forEach((f) => {
    form[f.key] = f.default !== undefined ? (Array.isArray(f.default) ? [...f.default] : f.default) : (f.type === 'checkbox' ? false : '');
});
if (config.seed) form[config.seed] = props.name;
Object.entries(props.defaults || {}).forEach(([key, value]) => { form[key] = value; config.onChange?.(form, key); });

onMounted(() => {
    fields.filter((f) => f.lookup).forEach(async (f) => {
        const { data } = await axios.get(route('lookup.list', f.lookup), { params: f.query || {} });
        lookups[f.key] = data.data;
        if (f.defaultFirst && !form[f.key] && data.data.length) form[f.key] = data.data[0].id;
    });
});

const visible = computed(() => fields.filter((f) => !f.show || f.show(form)));
const optionsOf = (f) => (f.lookup ? (lookups[f.key] || []) : (f.options || []).map((o) => ({ id: o.value, label: tx(o.label) })));

const toggleDay = (n) => {
    const i = form.work_days.indexOf(n);
    if (i >= 0) form.work_days.splice(i, 1); else form.work_days.push(n);
};

const submit = async () => {
    saving.value = true;
    errors.value = {};
    const payload = {};
    visible.value.forEach((f) => { payload[f.key] = form[f.key]; });
    try {
        const { data } = await axios.post(route(config.route), payload, { headers: { Accept: 'application/json' } });
        const record = config.pick ? config.pick(data) : data.record;
        record.label = record.label || (config.label ? config.label(record) : record.name || record.name_ar);
        emit('created', record);
    } catch (e) {
        const body = e.response?.data;
        errors.value = body?.errors ? Object.fromEntries(Object.entries(body.errors).map(([k, v]) => [k, v[0]])) : { _: body?.message || tx(t('z_resources_js_components_ui_quickcreatedialog.could_not_save_try_again')) };
    } finally {
        saving.value = false;
    }
};

const input = 'w-full rounded-xl border-slate-200 text-xs focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <Dialog :show="true" :title="tx(config.title)" :size="config.size || 'md'" @close="emit('close')">
        <form id="quick-create-form" @submit.prevent="submit" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs" data-quick-create>
            <p v-if="errors._" class="sm:col-span-2 text-rose-600">{{ errors._ }}</p>
            <template v-for="f in visible" :key="f.key">
                <div :class="f.span === 2 || f.type === 'textarea' || f.type === 'days' ? 'sm:col-span-2' : ''">
                    <label v-if="f.type === 'checkbox'" class="flex items-center gap-2 font-bold text-slate-700 pt-5">
                        <input type="checkbox" v-model="form[f.key]" class="rounded" /> {{ tx(f.label) }}
                    </label>
                    <Field v-else :label="tx(f.label) + (f.required ? ' *' : '')" :error="errors[f.key]" :hint="tx(f.hint)">
                        <div v-if="f.type === 'days'" class="flex flex-wrap gap-3">
                            <label v-for="[n, l] in dayNames" :key="n" class="flex items-center gap-1"><input type="checkbox" :checked="form.work_days.includes(n)" @change="toggleDay(n)" class="rounded" /> {{ tx(l) }}</label>
                        </div>
                        <SelectCreate v-else-if="f.type === 'select'" v-model="form[f.key]" :options="optionsOf(f)" :entity="f.entity" :defaults="f.defaults || null" :placeholder="tx(f.empty || $t('z_resources_js_components_ui_quickcreatedialog.choose'))" :allow-empty="!!f.empty || !f.required" :empty-label="tx(f.empty) || '—'" @update:modelValue="config.onChange?.(form, f.key)" @created="(r) => (lookups[f.key] = [...(lookups[f.key] || []), r])" />
                        <textarea v-else-if="f.type === 'textarea'" v-model="form[f.key]" rows="2" :class="input"></textarea>
                        <input v-else v-model="form[f.key]" :type="f.type || 'text'" :step="f.step" :required="f.required" :maxlength="f.maxlength" :class="input" />
                    </Field>
                </div>
            </template>
        </form>
        <template #footer>
            <button type="button" @click="emit('close')" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ tx($t('z_resources_js_components_ui_quickcreatedialog.cancel')) }}</button>
            <button type="submit" form="quick-create-form" :disabled="saving" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ saving ? tx($t('z_resources_js_components_ui_quickcreatedialog.saving')) : tx($t('z_resources_js_components_ui_quickcreatedialog.save_and_select')) }}</button>
        </template>
    </Dialog>
</template>

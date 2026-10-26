<script setup>
import { computed, ref } from 'vue';
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

/**
 * Permission matrix: modules -> screens (rows) x actions (columns).
 * v-model is the array of selected permission names ("module.resource.action").
 */
const props = defineProps({
    matrix: { type: Object, required: true },
    modelValue: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    // Permissions the current user may toggle (null = all).
    grantable: { type: Array, default: null },
});

const emit = defineEmits(['update:modelValue']);

const search = ref('');
const selected = computed(() => new Set(props.modelValue));
const grantableSet = computed(() => props.grantable ? new Set(props.grantable) : null);

const actionLabels = computed(() => Object.fromEntries(props.matrix.actions.map((a) => [a.key, a.label])));

const permName = (module, resource, action) => `${module.key}.${resource.key}.${action}`;

const isLocked = (name) => props.readonly || (grantableSet.value && !grantableSet.value.has(name));

const visibleModules = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.matrix.modules
        .map((module) => ({
            ...module,
            resources: term && !module.label.toLowerCase().includes(term)
                ? module.resources.filter((r) => r.label.toLowerCase().includes(term) || r.key.includes(term))
                : module.resources,
        }))
        .filter((module) => module.resources.length > 0)
        .map((module) => ({
            ...module,
            // Columns = actions used by at least one screen in this module, in registry order.
            columns: props.matrix.actions.map((a) => a.key).filter((key) => module.resources.some((r) => r.actions.includes(key))),
        }));
});

const update = (names, checked) => {
    const next = new Set(props.modelValue);
    names.filter((n) => !isLocked(n)).forEach((n) => (checked ? next.add(n) : next.delete(n)));
    emit('update:modelValue', [...next]);
};

const toggle = (name) => update([name], !selected.value.has(name));

const namesOfResource = (module, resource) => resource.actions.map((a) => permName(module, resource, a));
const namesOfModule = (module) => module.resources.flatMap((r) => namesOfResource(module, r));
const namesOfColumn = (module, action) => module.resources.filter((r) => r.actions.includes(action)).map((r) => permName(module, r, action));

const allChecked = (names) => names.length > 0 && names.every((n) => selected.value.has(n));
const someChecked = (names) => names.some((n) => selected.value.has(n));

const totalCount = computed(() => props.matrix.modules.reduce((sum, m) => sum + m.resources.reduce((s, r) => s + r.actions.length, 0), 0));
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="relative w-full sm:w-72">
                <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute top-1/2 -translate-y-1/2 start-3" />
                <input
                    v-model="search"
                    type="text"
                    :placeholder="$t('permissions.search')"
                    class="w-full ps-9 pe-3 py-2 text-xs rounded-xl border-slate-200 focus:border-indigo-400 focus:ring-indigo-400"
                />
            </div>
            <span class="text-xs text-slate-500">
                {{ $t('permissions.selected_count', { count: modelValue.length, total: totalCount }) }}
            </span>
        </div>

        <div v-for="module in visibleModules" :key="module.key" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 bg-slate-50 border-b border-slate-200">
                <label class="flex items-center gap-2 font-bold text-sm text-slate-800 cursor-pointer">
                    <input
                        type="checkbox"
                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        :checked="allChecked(namesOfModule(module))"
                        :indeterminate.prop="!allChecked(namesOfModule(module)) && someChecked(namesOfModule(module))"
                        :disabled="readonly"
                        @change="update(namesOfModule(module), $event.target.checked)"
                    />
                    {{ module.label }}
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-slate-500 border-b border-slate-100">
                            <th class="text-start font-semibold px-4 py-2 min-w-[180px]">{{ $t('permissions.screen') }}</th>
                            <th v-for="action in module.columns" :key="action" class="font-semibold px-2 py-2 text-center whitespace-nowrap">
                                <label class="inline-flex flex-col items-center gap-1 cursor-pointer">
                                    <span>{{ actionLabels[action] }}</span>
                                    <input
                                        type="checkbox"
                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        :checked="allChecked(namesOfColumn(module, action))"
                                        :disabled="readonly"
                                        @change="update(namesOfColumn(module, action), $event.target.checked)"
                                    />
                                </label>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="resource in module.resources" :key="resource.key" class="border-b border-slate-50 hover:bg-indigo-50/30">
                            <td class="px-4 py-2">
                                <label class="flex items-center gap-2 cursor-pointer text-slate-700 font-medium">
                                    <input
                                        type="checkbox"
                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        :checked="allChecked(namesOfResource(module, resource))"
                                        :indeterminate.prop="!allChecked(namesOfResource(module, resource)) && someChecked(namesOfResource(module, resource))"
                                        :disabled="readonly"
                                        @change="update(namesOfResource(module, resource), $event.target.checked)"
                                    />
                                    {{ resource.label }}
                                </label>
                            </td>
                            <td v-for="action in module.columns" :key="action" class="px-2 py-2 text-center">
                                <input
                                    v-if="resource.actions.includes(action)"
                                    type="checkbox"
                                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40"
                                    :checked="selected.has(permName(module, resource, action))"
                                    :disabled="isLocked(permName(module, resource, action))"
                                    :title="permName(module, resource, action)"
                                    @change="toggle(permName(module, resource, action))"
                                />
                                <span v-else class="text-slate-200">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Date range (or single "as of" date) filter that reloads the current route.
 */
const props = defineProps({
    routeName: { type: String, required: true },
    filters: { type: Object, default: () => ({}) },
    single: { type: Boolean, default: false }, // as_of only
    extra: { type: Object, default: () => ({}) }, // extra query values to keep
});

const form = reactive(props.single
    ? { as_of: props.filters.as_of }
    : { start_date: props.filters.start_date, end_date: props.filters.end_date });

const apply = () => router.get(route(props.routeName), { ...props.extra, ...form }, { preserveState: true });
</script>

<template>
    <form @submit.prevent="apply" class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-wrap items-end gap-3 text-xs mb-4 print:hidden">
        <template v-if="single">
            <label><span class="block font-bold text-slate-600 mb-1">كما في تاريخ</span><input v-model="form.as_of" type="date" class="rounded-xl border-slate-200 text-xs" /></label>
        </template>
        <template v-else>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.from') }}</span><input v-model="form.start_date" type="date" class="rounded-xl border-slate-200 text-xs" /></label>
            <label><span class="block font-bold text-slate-600 mb-1">{{ $t('ui.to') }}</span><input v-model="form.end_date" type="date" class="rounded-xl border-slate-200 text-xs" /></label>
        </template>
        <slot />
        <button type="submit" class="rounded-xl bg-indigo-600 text-white font-bold px-5 py-2 hover:bg-indigo-700">{{ $t('ui.apply') }}</button>
    </form>
</template>

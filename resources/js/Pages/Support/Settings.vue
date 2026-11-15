<script setup>
import { useI18n } from 'vue-i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { TrashIcon } from '@heroicons/vue/24/outline';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
const { t } = useI18n();

const props = defineProps({ sla: Array, categories: Array });
const form = useForm({ policies: props.sla.map((p) => ({ priority: p.priority, label: p.label, first_response_minutes: p.first_response_minutes, resolution_minutes: p.resolution_minutes })) });
useUnsavedGuard(form);
const save = () => form.put(route('support.settings.sla'), { preserveScroll: true, onSuccess: () => form.defaults() });
const hours = (m) => (Number(m) >= 60 ? t('x_support_settings.n_hours', { n0: (Number(m) / 60).toFixed(Number(m) % 60 ? 1 : 0) }) : t('x_support_settings.n_minutes', { n0: m }));

const cat = useForm({ name: '' });
const addCategory = () => cat.post(route('support.settings.categories.store'), { preserveScroll: true, onSuccess: () => cat.reset() });
const toggle = (c) => router.post(route('support.settings.categories.toggle', c.id), {}, { preserveScroll: true });
const remove = (c) => { if (window.confirm(t('x_support_settings.delete_the_category_n', { n0: c.name }))) router.delete(route('support.settings.categories.destroy', c.id), { preserveScroll: true }); };
const num = 'w-28 rounded-lg border-slate-200 text-xs font-mono';
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_support_settings.support_settings')" />
        <PageHeader :title="$t('x_support_settings.support_settings')" :subtitle="$t('x_support_settings.service_deadlines_sla_and_ticket')" :crumbs="[{ label: $t('x_support_settings.customer_service') }, { label: $t('x_support_settings.settings') }]">
            <template #actions>
                <button v-if="$can('support.settings.edit')" type="button" @click="save" :disabled="form.processing || !form.isDirty" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50" data-save-sla>{{ $t('x_support_settings.save_the_deadlines') }}</button>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto mb-5">
            <h2 class="px-4 py-3 text-xs font-bold text-slate-800 border-b border-slate-100">{{ $t('x_support_settings.service_deadlines_by_priority_mi') }}</h2>
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">{{ $t('x_support_settings.priority') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_settings.first_response') }}</th><th class="px-4 py-2 text-start">{{ $t('x_support_settings.resolution') }}</th></tr></thead>
                <tbody>
                    <tr v-for="(p, i) in form.policies" :key="p.priority" class="border-t border-slate-100" :data-sla-row="p.priority">
                        <td class="px-4 py-2 font-bold">{{ p.label }}</td>
                        <td class="px-4 py-2"><input v-model="p.first_response_minutes" type="text" inputmode="decimal" data-num :class="num" :disabled="!$can('support.settings.edit')" /> <span class="text-slate-400">≈ {{ hours(p.first_response_minutes) }}</span><p v-if="form.errors[`policies.${i}.first_response_minutes`]" class="text-rose-600">{{ form.errors[`policies.${i}.first_response_minutes`] }}</p></td>
                        <td class="px-4 py-2"><input v-model="p.resolution_minutes" type="text" inputmode="decimal" data-num :class="num" :disabled="!$can('support.settings.edit')" /> <span class="text-slate-400">≈ {{ hours(p.resolution_minutes) }}</span><p v-if="form.errors[`policies.${i}.resolution_minutes`]" class="text-rose-600">{{ form.errors[`policies.${i}.resolution_minutes`] }}</p></td>
                    </tr>
                </tbody>
            </table>
            <p class="px-4 py-3 text-3xs text-slate-500">{{ $t('x_support_settings.the_deadline_runs_from_the_momen') }}</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4">
            <h2 class="text-xs font-bold text-slate-800 mb-3">{{ $t('x_support_settings.ticket_categories') }}</h2>
            <form v-if="$can('support.settings.edit')" @submit.prevent="addCategory" class="flex gap-2 mb-3 text-xs">
                <input v-model="cat.name" required maxlength="255" :placeholder="$t('x_support_settings.a_new_category_e_g_invoices_faul')" class="flex-1 rounded-xl border-slate-200 text-xs" data-category-name />
                <button type="submit" :disabled="cat.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-add-category>{{ $t('x_support_settings.add') }}</button>
            </form>
            <ul class="divide-y divide-slate-100 text-xs">
                <li v-for="c in categories" :key="c.id" class="py-2 flex items-center justify-between" :data-category="c.name">
                    <span :class="c.is_active ? 'font-bold' : 'text-slate-400 line-through'">{{ c.name }} <span class="text-slate-400 font-normal">{{ $t('x_support_settings.n_tickets', { n0: c.tickets_count }) }}</span></span>
                    <span v-if="$can('support.settings.edit')" class="flex items-center gap-2">
                        <button type="button" class="text-indigo-600 font-bold" @click="toggle(c)">{{ c.is_active ? $t('x_support_settings.disable') : $t('x_support_settings.activate') }}</button>
                        <button type="button" class="p-1 text-slate-400 hover:text-rose-600" @click="remove(c)" :title="$t('x_support_settings.delete')"><TrashIcon class="w-4 h-4" /></button>
                    </span>
                </li>
                <li v-if="!categories.length" class="py-6 text-center text-slate-400">{{ $t('x_support_settings.no_categories_yet') }}</li>
            </ul>
        </div>
    </AppLayout>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import ImportButton from '@/Components/UI/ImportButton.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
const { t } = useI18n();

const props = defineProps({ costCenters: Array });

const open = ref(false);
const editing = ref(null);
const form = useForm({ parent_id: '', code: '', name_ar: '', name_en: '', is_active: true });

// Flatten the tree in display order with depth.
const tree = computed(() => {
    const byParent = {};
    props.costCenters.forEach((c) => (byParent[c.parent_id ?? 0] ??= []).push(c));
    const out = [];
    const walk = (parent, depth) => (byParent[parent] || []).forEach((c) => { out.push({ ...c, depth }); walk(c.id, depth + 1); });
    walk(0, 0);
    return out;
});

const create = () => { editing.value = null; form.reset(); form.clearErrors(); open.value = true; };
const edit = (c) => { editing.value = c; Object.assign(form, { parent_id: c.parent_id ?? '', code: c.code, name_ar: c.name_ar, name_en: c.name_en ?? '', is_active: c.is_active }); open.value = true; };
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.put(route('accounting.cost-centers.update', editing.value.id), opts) : form.post(route('accounting.cost-centers.store'), opts);
};
const remove = (c) => confirm(t('a_cc.confirm_delete', { name: c.name_ar })) && router.delete(route('accounting.cost-centers.destroy', c.id), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_cc.cost_centers')" />
        <PageHeader :title="$t('a_cc.cost_centers')" :subtitle="$t('a_cc.analytical_dimensions_to_distrib')" :crumbs="[{ label: 'المحاسبة' }, { label: 'مراكز التكلفة' }]">
            <template #actions>
                <button v-if="$can('accounting.cost_centers.create')" @click="create" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('a_cc.cost_center') }}</button>
                <ImportButton v-if="$can('accounting.cost_centers.create')" type="cost_centers" :title="$t('a_cc.import_cost_centers_from_excel')" />
</template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('ui.code') }}</th><th class="text-start px-4 py-2">{{ $t('ui.name') }}</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th class="px-4 py-2"></th></tr></thead>
                <tbody>
                    <tr v-for="c in tree" :key="c.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono text-slate-500">{{ c.code }}</td>
                        <td class="px-4 py-2 font-semibold text-slate-800" :style="{ paddingInlineStart: (16 + c.depth * 18) + 'px' }">{{ c.name_ar }}</td>
                        <td class="px-4 py-2 text-center"><span :class="c.is_active ? 'text-emerald-600' : 'text-slate-400'">{{ c.is_active ? $t('s_common.active_m') : $t('a_cheques.suspended') }}</span></td>
                        <td class="px-4 py-2 text-end">
                            <button v-if="$can('accounting.cost_centers.edit')" @click="edit(c)" class="p-1.5 text-slate-400 hover:text-indigo-600"><PencilSquareIcon class="w-4 h-4" /></button>
                            <button v-if="$can('accounting.cost_centers.delete')" @click="remove(c)" class="p-1.5 text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!tree.length"><td colspan="4" class="py-10 text-center text-slate-400">{{ $t('a_cc.no_cost_centers_yet') }}</td></tr>
                </tbody>
            </table>
        </div>

        <Dialog :show="open" :title="editing ? $t('a_cc.edit') : $t('a_cc.new')" @close="open = false">
            <form @submit.prevent="save" class="space-y-3 text-xs">
                <Field :label="$t('a_cc.parent_center')"><SelectCreate v-model="form.parent_id" :options="costCenters.filter(x => x.id !== editing?.id)" entity="cost_center" :label="(c) => `${c.code} - ${c.name_ar}`" :placeholder="$t('a_cc.top_level')" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field :label="$t('ui.code')" :error="form.errors.code"><input v-model="form.code" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('a_cc.name_in_arabic')" :error="form.errors.name_ar"><input v-model="form.name_ar" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <Field :label="$t('a_cc.name_in_english')"><input v-model="form.name_en" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> {{ $t('a_cc.active') }}</label>
            </form>
            <template #footer>
                <button @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('ui.cancel') }}</button>
                <button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

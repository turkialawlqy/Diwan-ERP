<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ routing: Object, products: Array, workCenters: Array });
const editing = !!props.routing?.id;
const r = props.routing;
const blank = (seq) => ({ seq, name: '', work_center_id: props.workCenters?.[0]?.id || '', setup_minutes: 0, run_minutes: 0 });
const form = useForm({
    code: r.code || '', name: r.name || '', product_id: r.product_id || '', is_active: r.is_active ?? true, notes: r.notes || '',
    operations: r.operations?.length ? r.operations.map((o) => ({ seq: o.seq, name: o.name, work_center_id: o.work_center_id, setup_minutes: o.setup_minutes, run_minutes: o.run_minutes })) : [blank(10)],
});
useUnsavedGuard(form);
const add = () => form.operations.push(blank((Math.max(0, ...form.operations.map((o) => Number(o.seq) || 0)) || 0) + 10));
const submit = () => (editing ? form.put(route('manufacturing.routings.update', r.id)) : form.post(route('manufacturing.routings.store')));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head :title="editing ? $t('mfg_manufacturing_routings_form.edit_a_routing') : $t('mfg_manufacturing_routings_form.new_routing')" />
        <PageHeader :title="editing ? $t('mfg_manufacturing_routings_form.edit_a_routing_2') : $t('mfg_manufacturing_routings_form.new_routing')" :crumbs="[{ label: $t('mfg_manufacturing_routings_form.manufacturing') }, { label: $t('mfg_manufacturing_routings_form.routings'), href: route('manufacturing.routings.index') }, { label: editing ? $t('mfg_manufacturing_routings_form.edit') : $t('mfg_manufacturing_routings_form.new') }]" />
        <form @submit.prevent="submit" class="space-y-5">
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field :label="$t('mfg_manufacturing_routings_form.code')" :error="form.errors.code"><input v-model="form.code" :class="input" class="font-mono" required /></Field>
                <Field :label="$t('mfg_manufacturing_routings_form.name')" :error="form.errors.name"><input v-model="form.name" :class="input" required /></Field>
                <Field :label="$t('mfg_manufacturing_routings_form.product_optional')"><SelectCreate v-model="form.product_id" :options="products" :search="{ route: 'lookup.products', params: { stockable: 1 } }" entity="product" label="name_ar" placeholder="—" /></Field>
                <label class="inline-flex items-center gap-2 text-xs"><input v-model="form.is_active" type="checkbox" class="rounded" /> {{ $t('mfg_manufacturing_routings_form.active') }}</label>
            </div>
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
                <div class="flex items-center justify-between mb-3"><h2 class="font-black text-slate-800 text-sm">{{ $t('mfg_manufacturing_routings_form.operations') }}</h2><button type="button" @click="add" class="text-xs font-bold text-indigo-600 inline-flex items-center gap-1"><PlusIcon class="w-4 h-4" /> {{ $t('mfg_manufacturing_routings_form.add_an_operation') }}</button></div>
                <p v-if="form.errors.operations" class="text-xs text-rose-600 mb-2">{{ form.errors.operations }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-slate-500"><tr><th class="text-start p-2 w-20">{{ $t('mfg_manufacturing_routings_form.sequence') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_routings_form.operation') }}</th><th class="text-start p-2">{{ $t('mfg_manufacturing_routings_form.work_center') }}</th><th class="text-start p-2 w-32">{{ $t('mfg_manufacturing_routings_form.setup_min') }}</th><th class="text-start p-2 w-32">{{ $t('mfg_manufacturing_routings_form.run_unit_min') }}</th><th class="w-10"></th></tr></thead>
                        <tbody>
                            <tr v-for="(op, i) in form.operations" :key="i" class="border-t border-slate-100">
                                <td class="p-2"><input v-model.number="op.seq" type="text" inputmode="decimal" data-num :class="input" /></td>
                                <td class="p-2"><input v-model="op.name" :class="input" :placeholder="$t('mfg_manufacturing_routings_form.cutting_pressing_coating')" required /></td>
                                <td class="p-2"><SelectCreate v-model="op.work_center_id" :options="workCenters" entity="work_center" :label="(w) => `${w.code} — ${w.name}`" :allow-empty="false" /></td>
                                <td class="p-2"><input v-model.number="op.setup_minutes" type="text" inputmode="decimal" data-num step="0.01" :class="input" /></td>
                                <td class="p-2"><input v-model.number="op.run_minutes" type="text" inputmode="decimal" data-num step="0.0001" :class="input" /></td>
                                <td class="p-2"><button type="button" :disabled="form.operations.length <= 1" @click="form.operations.splice(i, 1)" class="p-1 text-slate-400 hover:text-rose-600 disabled:opacity-20"><TrashIcon class="w-4 h-4" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">{{ $t('mfg_manufacturing_routings_form.save') }}</button>
                <Link :href="route('manufacturing.routings.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">{{ $t('mfg_manufacturing_routings_form.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

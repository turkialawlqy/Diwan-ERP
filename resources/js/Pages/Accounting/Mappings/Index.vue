<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

const props = defineProps({ roles: Array, mapping: Object, accounts: Array, dimensionPolicy: Object });
const dimForm = useForm({ cost_center: props.dimensionPolicy?.cost_center || 'optional', project: props.dimensionPolicy?.project || 'optional' });
const saveDimensions = () => dimForm.put(route('accounting.mappings.dimensions'), { preserveScroll: true });
const modes = computed(() => [
    { value: 'optional', label: t('a_mappings.opt'), hint: t('a_mappings.opt_hint') },
    { value: 'required', label: t('a_mappings.req'), hint: t('a_mappings.req_hint') },
    { value: 'pnl', label: t('a_mappings.pnl'), hint: t('a_mappings.pnl_hint') },
]);
const form = useForm({ mapping: { ...props.mapping } });

const typeLabels = computed(() => ({ asset: t('a_mappings.ty_asset'), liability: t('a_mappings.ty_liability'), equity: t('a_mappings.ty_equity'), revenue: t('a_mappings.ty_revenue'), expense: t('a_mappings.ty_expense') }));
const grouped = computed(() => Object.keys(typeLabels.value).map((type) => ({ type, label: typeLabels.value[type], roles: props.roles.filter((r) => r.type === type) })));
const accountsFor = (type) => props.accounts.filter((a) => a.type === type);
const save = () => form.put(route('accounting.mappings.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head :title="$t('a_mappings.account_mapping')" />
        <PageHeader :title="$t('a_mappings.automatic_account_mapping')" :subtitle="$t('a_mappings.the_accounts_the_system_posts_in')" :crumbs="[{ label: 'المحاسبة' }, { label: 'ربط الحسابات' }]">
            <template #actions>
                <button v-if="$can('accounting.mappings.edit')" @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-4" data-dimension-policy>
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h3 class="font-black text-sm text-slate-800">{{ $t('a_mappings.cost_center_and_project_policy') }}</h3>
                    <p class="text-xs text-slate-500 mt-1">{{ $t('a_mappings.cost_center_and_project_are_anal') }} <b>{{ $t('a_mappings.distribution_lines') }}</b> {{ $t('a_mappings.that_the_user_chooses_manual_ent') }}</p>
                </div>
                <button v-if="$can('accounting.mappings.edit')" @click="saveDimensions" :disabled="dimForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold shrink-0">{{ $t('a_mappings.save_the_policy') }}</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div v-for="d in [{ key: 'cost_center', label: $t('a_mappings.cost_center') }, { key: 'project', label: $t('a_mappings.project') }]" :key="d.key">
                    <label class="block font-bold text-slate-700 mb-1">{{ d.label }} {{ $t('a_mappings.in_ops') }}</label>
                    <select v-model="dimForm[d.key]" :disabled="!$can('accounting.mappings.edit')" class="w-full rounded-xl border-slate-200 text-xs">
                        <option v-for="m in modes" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <p class="text-slate-500 mt-1">{{ modes.find((m) => m.value === dimForm[d.key])?.hint }}</p>
                    <p v-if="dimForm.errors[d.key]" class="text-rose-600">{{ dimForm.errors[d.key] }}</p>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-3">{{ $t('a_mappings.recommendation_start_with_cost_c') }}</p>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div v-for="group in grouped" :key="group.type" class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-sm text-slate-800 mb-3">{{ group.label }}</h3>
                <div class="space-y-2">
                    <div v-for="role in group.roles" :key="role.key" class="grid grid-cols-5 items-center gap-2 text-xs">
                        <span class="col-span-2 font-semibold text-slate-700">{{ role.label }}</span>
                        <select v-model="form.mapping[role.key]" :disabled="!$can('accounting.mappings.edit')" class="col-span-3 rounded-xl border-slate-200 text-xs">
                            <option :value="null">{{ $t('a_mappings.not_mapped') }}</option>
                            <option v-for="a in accountsFor(group.type)" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option>
                        </select>
                        <p v-if="form.errors[`mapping.${role.key}`]" class="col-span-5 text-rose-600">{{ form.errors[`mapping.${role.key}`] }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ roles: Array, mapping: Object, accounts: Array });
const form = useForm({ mapping: { ...props.mapping } });

const typeLabels = { asset: 'الأصول', liability: 'الخصوم', equity: 'حقوق الملكية', revenue: 'الإيرادات', expense: 'المصروفات' };
const grouped = computed(() => Object.keys(typeLabels).map((type) => ({ type, label: typeLabels[type], roles: props.roles.filter((r) => r.type === type) })));
const accountsFor = (type) => props.accounts.filter((a) => a.type === type);
const save = () => form.put(route('accounting.mappings.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="ربط الحسابات" />
        <PageHeader title="ربط الحسابات الآلي" subtitle="الحسابات التي يرحّل عليها النظام قيود الفواتير والسندات والرواتب والمخزون تلقائياً" :crumbs="[{ label: 'المحاسبة' }, { label: 'ربط الحسابات' }]">
            <template #actions>
                <button v-if="$can('accounting.mappings.edit')" @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div v-for="group in grouped" :key="group.type" class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-sm text-slate-800 mb-3">{{ group.label }}</h3>
                <div class="space-y-2">
                    <div v-for="role in group.roles" :key="role.key" class="grid grid-cols-5 items-center gap-2 text-xs">
                        <span class="col-span-2 font-semibold text-slate-700">{{ role.label }}</span>
                        <select v-model="form.mapping[role.key]" :disabled="!$can('accounting.mappings.edit')" class="col-span-3 rounded-xl border-slate-200 text-xs">
                            <option :value="null">— غير مربوط —</option>
                            <option v-for="a in accountsFor(group.type)" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option>
                        </select>
                        <p v-if="form.errors[`mapping.${role.key}`]" class="col-span-5 text-rose-600">{{ form.errors[`mapping.${role.key}`] }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

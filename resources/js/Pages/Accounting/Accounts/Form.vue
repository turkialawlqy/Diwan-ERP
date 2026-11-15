<script setup>
import { useI18n } from 'vue-i18n';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

const props = defineProps({
    account: { type: Object, default: null },
    accounts: Array,
    parentAccounts: Array,
    parentId: { type: [Number, String], default: '' },
});

const editing = !!props.account?.id;
const typeLabels = computed(() => ({ asset: t('a_common.assets'), liability: t('a_common.liabilities'), equity: t('a_common.equity'), revenue: t('a_common.revenue'), expense: t('a_common.expenses') }));
const parentMap = computed(() => Object.fromEntries((props.accounts || []).map((a) => [a.id, a])));

// A new sub-account starts from its parent: type, nature and the next free code.
const suggestChildCode = (parentId) => {
    const parent = parentMap.value[parentId];
    if (!parent) return '';
    const siblings = (props.accounts || []).filter((a) => a.parent_id === parentId);
    if (!siblings.length) return `${parent.code}01`;
    const top = siblings.map((s) => parseInt(s.code, 10) || 0).sort((a, b) => b - a)[0];
    return top > 0 ? String(top + 1) : `${parent.code}${siblings.length + 1}`;
};
const parent = props.parentId ? parentMap.value[props.parentId] : null;

const editForm = useForm({
    id: props.account?.id ?? null,
    code: props.account?.code || (parent ? suggestChildCode(parent.id) : ''),
    name_ar: props.account?.name_ar || '',
    name_en: props.account?.name_en || '',
    parent_id: props.account?.parent_id || parent?.id || '',
    type: props.account?.type || parent?.type || 'asset',
    nature: props.account?.nature || parent?.nature || 'debit',
    is_parent: Boolean(props.account?.is_parent),
    balance: props.account?.balance ?? '0.00',
    is_active: props.account ? Boolean(props.account.is_active) : true,
});
useUnsavedGuard(editForm);

const submitEditAccount = () => (editing
    ? editForm.put(route('accounting.accounts.update', props.account.id))
    : editForm.post(route('accounting.accounts.store')));
</script>

<template>
    <AppLayout>
        <Head :title="props.account?.id ? $t('a_account_form.edit') : $t('a_account_form.new')" />
        <PageHeader :title="props.account?.id ? $t('a_account_form.edit') : $t('a_account_form.new')" :crumbs="[{ label: 'المحاسبة' }, { label: 'دليل الحسابات', href: route('accounting.accounts.index') }, { label: props.account?.id ? 'تعديل' : 'جديد' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitEditAccount" class="form-grid text-xs">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.parent_account') }}</label>
                                <SelectCreate v-model="editForm.parent_id" :options="parentAccounts" entity="account" :label="(p) => `${p.code} - ${p.name_ar} (${typeLabels[p.type]})`" :placeholder="$t('a_account_form.top_level_account_no_parent')" />
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.account_code') }} <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model="editForm.code" 
                                        type="text" 
                                        required 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500" 
                                    />
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.account_nature') }} <span class="text-rose-500">*</span></label>
                                    <select 
                                        v-model="editForm.nature" 
                                        class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option value="debit">{{ $t('a_account_form.debit') }}</option>
                                        <option value="credit">{{ $t('a_account_form.credit') }}</option>
                                    </select>
                                </div>
                            </div>
            
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.account_name_in_arabic') }} <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="editForm.name_ar" 
                                    type="text" 
                                    required 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
            
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.account_name_in_english') }}</label>
                                <input 
                                    v-model="editForm.name_en" 
                                    type="text" 
                                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500" 
                                />
                            </div>
            
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.account_type') }} <span class="text-rose-500">*</span></label>
                                    <select 
                                        v-model="editForm.type" 
                                        class="w-full py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                                    >
                                        <option value="asset">{{ $t('a_account_form.assets') }}</option>
                                        <option value="liability">{{ $t('a_account_form.liabilities_and_obligations') }}</option>
                                        <option value="equity">{{ $t('a_account_form.equity') }}</option>
                                        <option value="revenue">{{ $t('a_account_form.revenue') }}</option>
                                        <option value="expense">{{ $t('a_account_form.expenses') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block font-medium text-slate-700 mb-1">{{ $t('a_account_form.account_balance_sar') }}</label>
                                    <input 
                                        v-model.number="editForm.balance" 
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01" 
                                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:ring-indigo-500 focus:border-indigo-500 text-end" 
                                    />
                                </div>
                            </div>
            
                            <div class="flex items-center gap-6 pt-2">
                                <div class="flex items-center gap-2">
                                    <input 
                                        v-model="editForm.is_parent" 
                                        id="edit_is_parent" 
                                        type="checkbox" 
                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <label for="edit_is_parent" class="font-medium text-slate-700 cursor-pointer">
                                        {{ $t('a_account_form.parent_account_folder') }}
                                    </label>
                                </div>
            
                                <div class="flex items-center gap-2">
                                    <input 
                                        v-model="editForm.is_active" 
                                        id="edit_is_active" 
                                        type="checkbox" 
                                        class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <label for="edit_is_active" class="font-medium text-slate-700 cursor-pointer">
                                        {{ $t('a_account_form.active_and_available_for_transac') }}
                                    </label>
                                </div>
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <Link :href="route('accounting.accounts.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">{{ $t('a_account_form.cancel') }}</Link>
                                <button 
                                    type="submit" 
                                    :disabled="editForm.processing"
                                    class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                                >
                                    {{ $t('a_account_form.save_changes') }}
                                </button>
                            </div>
                        </form>
        </div>
    </AppLayout>
</template>

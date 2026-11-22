<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { today } from '@/utils/format';

const props = defineProps({ accounts: Array });

const form = useForm({
    from_account_id: props.accounts?.[0]?.id || '',
    to_account_id: props.accounts?.[1]?.id || '',
    amount: '',
    transfer_date: today(),
    reference: '',
    notes: '',
});
useUnsavedGuard(form);

const selectedFromAccount = computed(() => props.accounts?.find((a) => a.id === form.from_account_id));

const submitTransfer = () => form.post(route('finance.transfers.store'));
</script>

<template>
    <AppLayout>
        <Head :title="$t('fin_finance_transfers_form.new_money_transfer')" />
        <PageHeader :title="$t('fin_finance_transfers_form.new_money_transfer')" :crumbs="[{ label: $t('fin_finance_transfers_form.finance') }, { label: $t('fin_finance_transfers_form.transfers'), href: route('finance.transfers.index') }, { label: $t('fin_finance_transfers_form.new') }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitTransfer" class="form-grid text-xs">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('fin_finance_transfers_form.from_account_cash_box') }}</label>
                                    <SelectCreate v-model="form.from_account_id" :options="accounts" entity="bank" :label="(a) => `${a.name} (${Number(a.current_balance).toLocaleString('en-US')} ${a.currency})`" :allow-empty="false" required />
                                    <p v-if="form.errors.from_account_id" class="text-[11px] text-rose-600 mt-1">{{ form.errors.from_account_id }}</p>
                                </div>
            
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('fin_finance_transfers_form.to_account_cash_box') }}</label>
                                    <SelectCreate v-model="form.to_account_id" :options="accounts" entity="bank" :label="(a) => `${a.name} (${Number(a.current_balance).toLocaleString('en-US')} ${a.currency})`" :allow-empty="false" required />
                                    <p v-if="form.errors.to_account_id" class="text-[11px] text-rose-600 mt-1">{{ form.errors.to_account_id }}</p>
                                </div>
                            </div>
            
                            <div v-if="selectedFromAccount" class="p-3 bg-indigo-50/60 rounded-xl border border-indigo-100 text-xs flex items-center justify-between">
                                <span class="text-indigo-900 font-medium">{{ $t('fin_finance_transfers_form.available_balance_in_the_source') }}</span>
                                <span class="font-bold font-mono text-indigo-700">
                                    {{ Number(selectedFromAccount.current_balance).toLocaleString('en-US') }} {{ selectedFromAccount.currency }}
                                </span>
                            </div>
            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('fin_finance_transfers_form.transferred_amount') }}</label>
                                    <input 
                                        v-model.number="form.amount" 
                                        type="text" inputmode="decimal" data-num 
                                        step="0.01"
                                        min="0.01"
                                        required
                                        placeholder="0.00"
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono font-bold"
                                    />
                                    <p v-if="form.errors.amount" class="text-[11px] text-rose-600 mt-1">{{ form.errors.amount }}</p>
                                </div>
            
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('fin_finance_transfers_form.transfer_date') }}</label>
                                    <DatePicker 
                                        v-model="form.transfer_date" 
                                        required
                                        class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono" />
                                </div>
                            </div>
            
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('fin_finance_transfers_form.notice_number_or_bank_reference') }}</label>
                                <input 
                                    v-model="form.reference" 
                                    type="text" 
                                    :placeholder="$t('fin_finance_transfers_form.example_ref_994821')"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                                />
                            </div>
            
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $t('fin_finance_transfers_form.transfer_notes') }}</label>
                                <input 
                                    v-model="form.notes" 
                                    type="text" 
                                    :placeholder="$t('fin_finance_transfers_form.funding_a_cash_box_moving_cash_t')"
                                    class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                                />
                            </div>
            
                            <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                                <Link :href="route('finance.transfers.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">{{ $t('fin_finance_transfers_form.cancel') }}</Link>
                                <button 
                                    type="submit" 
                                    :disabled="form.processing"
                                    class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition disabled:opacity-50"
                                >
                                    {{ $t('fin_finance_transfers_form.execute_the_transfer') }}
                                </button>
                            </div>
                        </form>
        </div>
    </AppLayout>
</template>

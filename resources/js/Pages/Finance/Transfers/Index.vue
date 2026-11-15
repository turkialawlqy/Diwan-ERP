<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import CancelDocument from '@/Components/CancelDocument.vue';
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    ArrowsRightLeftIcon,
    BanknotesIcon,
    BuildingLibraryIcon,
    XMarkIcon,
    CheckCircleIcon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    accounts: Array,
    transfers: Object,
    stats: Object,
});

</script>

<template>
    <AppLayout>
        <Head :title="$t('fin_finance_transfers_index.money_transfers')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('fin_finance_transfers_index.money_transfers')" :crumbs="[{ label: $t('fin_finance_transfers_index.finance') }, { label: $t('fin_finance_transfers_index.money_transfers') }]">
<template #actions>
<Link 
                        :href="route('finance.transfers.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        :title="$t('fin_finance_transfers_index.refresh_data')"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
<Link
                        :href="route('finance.banks.index')"
                        class="px-4 py-2.5 bg-white border border-slate-200/80 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-bold shadow-xs transition-all flex items-center gap-1.5"
                    >
                        <BuildingLibraryIcon class="w-4 h-4 text-indigo-600" />
                        <span>{{ $t('fin_finance_transfers_index.accounts_and_cash_boxes') }}</span>
                    </Link>
                    <Link v-if="$can('finance.transfers.create')" :href="route('finance.transfers.create')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('fin_finance_transfers_index.new_money_transfer') }}</span>
                    </Link>
</template>
</PageHeader>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-semibold mb-1">{{ $t('fin_finance_transfers_index.total_transferred_amounts') }}</p>
                        <h3 class="text-xl font-black text-slate-900 font-sans">
                            {{ Number(stats?.total_transfers_amount || 0).toLocaleString('en-US') }} <span class="text-xs font-normal text-slate-400">{{ $cur() }}</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-semibold mb-1">{{ $t('fin_finance_transfers_index.number_of_transfers_made') }}</p>
                        <h3 class="text-xl font-black text-emerald-600">{{ stats?.total_transfers_count ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <ArrowsRightLeftIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Transfers Table -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                                <th class="px-6 py-4">{{ $t('fin_finance_transfers_index.entry_number_reference') }}</th>
                                <th class="px-6 py-4">{{ $t('fin_finance_transfers_index.transfer_date') }}</th>
                                <th class="px-6 py-4">{{ $t('fin_finance_transfers_index.transaction_description') }}</th>
                                <th class="px-6 py-4 text-center">{{ $t('fin_finance_transfers_index.transferred_amount') }}</th>
                                <th class="px-6 py-4 text-center">{{ $t('fin_finance_transfers_index.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr 
                                v-for="t in transfers?.data || []" 
                                :key="t.id"
                                class="hover:bg-slate-50/60 transition"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">
                                            <DocumentTextIcon class="w-4 h-4" />
                                        </div>
                                        <div>
                                            <p class="font-bold text-indigo-600 font-mono">{{ t.entry_number }}</p>
                                            <p class="text-[10px] text-slate-400 font-mono">{{ t.reference }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-slate-600" dir="ltr">
                                    {{ t.date }}
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-800">
                                    {{ t.description }}
                                </td>
                                <td class="px-6 py-4 text-center font-bold text-slate-900 font-sans">
                                    {{ Number(t.total_debit).toLocaleString('en-US') }} {{ $cur() }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-3">
                                        <span v-if="t.status !== 'reversed'" class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 text-xs font-bold px-2.5 py-1 rounded-full border border-emerald-200">
                                            <CheckCircleIcon class="w-3.5 h-3.5" />
                                            <span>{{ $t('fin_finance_transfers_index.posted_and_complete') }}</span>
                                        </span>
                                        <CancelDocument v-if="$can('finance.transfers.cancel') || t.status === 'reversed'" :url="route('finance.transfers.cancel', t.id)" :label="$t('fin_finance_transfers_index.transfer_2')" :status="t.status === 'reversed' ? 'cancelled' : null" />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!transfers?.data || transfers.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <ArrowsRightLeftIcon class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <span>{{ $t('fin_finance_transfers_index.no_money_transfers_recorded_yet') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="transfers?.links && transfers.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        {{ $t('fin_finance_transfers_index.view') }} <span class="font-bold text-slate-700">{{ transfers.from || 0 }}</span> {{ $t('fin_finance_transfers_index.to') }} <span class="font-bold text-slate-700">{{ transfers.to || 0 }}</span> {{ $t('fin_finance_transfers_index.of') }} <span class="font-bold text-slate-700">{{ transfers.total }}</span> {{ $t('fin_finance_transfers_index.transfer') }}
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in transfers.links" :key="i">
                            <Link 
                                v-if="link.url"
                                :href="link.url"
                                :class="[
                                    link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50',
                                    'min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs transition-colors'
                                ]"
                                v-html="link.label"
                            />
                            <span 
                                v-else
                                class="min-w-8 h-8 px-2 flex items-center justify-center rounded-lg text-xs text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
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

const isModalOpen = ref(false);

const form = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    transfer_date: new Date().toISOString().substring(0, 10),
    reference: '',
    notes: '',
});

const selectedFromAccount = computed(() => {
    return props.accounts?.find(a => a.id === form.from_account_id);
});

const openTransferModal = () => {
    form.reset();
    form.transfer_date = new Date().toISOString().substring(0, 10);
    form.from_account_id = props.accounts?.[0]?.id || '';
    form.to_account_id = props.accounts?.[1]?.id || '';
    isModalOpen.value = true;
};

const submitTransfer = () => {
    form.post(route('finance.transfers.store'), {
        onSuccess: () => {
            isModalOpen.value = false;
            form.reset();
        }
    });
};
</script>

<template>
    <AppLayout>
        <Head title="التحويلات المالية" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <!-- Breadcrumbs & Refresh -->
                <div class="flex items-center gap-2">
                    <Link 
                        :href="route('finance.transfers.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        title="تحديث البيانات"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>

                    <nav class="inline-flex items-center bg-white border border-slate-200/80 rounded-xl px-4 py-2 text-xs sm:text-sm font-medium text-slate-600 shadow-xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 flex items-center transition-colors">
                            <HomeIcon class="w-4 h-4 ml-1.5 text-slate-400" />
                            <span>الرئيسية</span>
                        </Link>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-slate-500">المالية</span>
                        <ChevronRightIcon class="w-3.5 h-3.5 mx-2 text-slate-400 rtl:rotate-180" />
                        <span class="text-indigo-600 font-bold">التحويلات المالية</span>
                    </nav>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('finance.banks.index')"
                        class="px-4 py-2.5 bg-white border border-slate-200/80 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-bold shadow-xs transition-all flex items-center gap-1.5"
                    >
                        <BuildingLibraryIcon class="w-4 h-4 text-indigo-600" />
                        <span>الحسابات والخزائن</span>
                    </Link>
                    <button 
                        @click="openTransferModal"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                    >
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>تحويل مالي جديد</span>
                    </button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-semibold mb-1">إجمالي مبالغ التحويلات</p>
                        <h3 class="text-xl font-black text-slate-900 font-sans">
                            {{ Number(stats?.total_transfers_amount || 0).toLocaleString() }} <span class="text-xs font-normal text-slate-400">{{ $cur() }}</span>
                        </h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BanknotesIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-semibold mb-1">عدد عمليات التحويل المنفذة</p>
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
                                <th class="px-6 py-4">رقم القيد / المرجع</th>
                                <th class="px-6 py-4">تاريخ التحويل</th>
                                <th class="px-6 py-4">بيان العملية</th>
                                <th class="px-6 py-4 text-center">المبلغ المحول</th>
                                <th class="px-6 py-4 text-center">الحالة</th>
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
                                    {{ Number(t.total_debit).toLocaleString() }} {{ $cur() }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-3">
                                        <span v-if="t.status !== 'reversed'" class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 text-xs font-bold px-2.5 py-1 rounded-full border border-emerald-200">
                                            <CheckCircleIcon class="w-3.5 h-3.5" />
                                            <span>مرحل ومكتمل</span>
                                        </span>
                                        <CancelDocument v-if="$can('finance.transfers.cancel') || t.status === 'reversed'" :url="route('finance.transfers.cancel', t.id)" label="التحويل" :status="t.status === 'reversed' ? 'cancelled' : null" />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!transfers?.data || transfers.data.length === 0">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <ArrowsRightLeftIcon class="w-10 h-10 mx-auto text-slate-300 mb-2" />
                                    <span>لا توجد عمليات تحويل مالي مسجلة حتى الآن</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="transfers?.links && transfers.links.length > 3" class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-slate-500 font-medium">
                        عرض <span class="font-bold text-slate-700">{{ transfers.from || 0 }}</span> إلى <span class="font-bold text-slate-700">{{ transfers.to || 0 }}</span> من إجمالي <span class="font-bold text-slate-700">{{ transfers.total }}</span> تحويل
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

        <!-- Add Transfer Modal -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs">
            <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl border border-slate-100 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 class="font-black text-slate-900 text-sm">تسجيل تحويل مالي بين الحسابات</h3>
                    <button @click="isModalOpen = false" class="text-slate-400 hover:text-slate-600">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <form @submit.prevent="submitTransfer" class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">من حساب / خزينة *</label>
                            <select 
                                v-model="form.from_account_id"
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            >
                                <option v-for="a in accounts" :key="a.id" :value="a.id">
                                    {{ a.name }} ({{ Number(a.current_balance).toLocaleString() }} {{ a.currency }})
                                </option>
                            </select>
                            <p v-if="form.errors.from_account_id" class="text-[11px] text-rose-600 mt-1">{{ form.errors.from_account_id }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">إلى حساب / خزينة *</label>
                            <select 
                                v-model="form.to_account_id"
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                            >
                                <option v-for="a in accounts" :key="a.id" :value="a.id">
                                    {{ a.name }} ({{ Number(a.current_balance).toLocaleString() }} {{ a.currency }})
                                </option>
                            </select>
                            <p v-if="form.errors.to_account_id" class="text-[11px] text-rose-600 mt-1">{{ form.errors.to_account_id }}</p>
                        </div>
                    </div>

                    <div v-if="selectedFromAccount" class="p-3 bg-indigo-50/60 rounded-xl border border-indigo-100 text-xs flex items-center justify-between">
                        <span class="text-indigo-900 font-medium">الرصيد المتاح في الحساب المصدر:</span>
                        <span class="font-bold font-mono text-indigo-700">
                            {{ Number(selectedFromAccount.current_balance).toLocaleString() }} {{ selectedFromAccount.currency }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المحول *</label>
                            <input 
                                v-model="form.amount" 
                                type="number" 
                                step="0.01"
                                min="0.01"
                                required
                                placeholder="0.00"
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono font-bold"
                            />
                            <p v-if="form.errors.amount" class="text-[11px] text-rose-600 mt-1">{{ form.errors.amount }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ التحويل *</label>
                            <input 
                                v-model="form.transfer_date" 
                                type="date" 
                                required
                                class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">رقم الإشعار أو المرجع البنكي</label>
                        <input 
                            v-model="form.reference" 
                            type="text" 
                            placeholder="مثال: REF-994821"
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600 font-mono"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات التحويل</label>
                        <input 
                            v-model="form.notes" 
                            type="text" 
                            placeholder="تغذية صندوق، تحويل سيولة للحساب الجاري..."
                            class="w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600"
                        />
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                        <button 
                            type="button" 
                            @click="isModalOpen = false"
                            class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition"
                        >
                            إلغاء
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition disabled:opacity-50"
                        >
                            تنفيذ التحويل
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

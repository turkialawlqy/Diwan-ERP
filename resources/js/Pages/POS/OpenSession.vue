<script setup>
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import BrandLogo from '../../Components/BrandLogo.vue';

const props = defineProps({
    registers: Array,
    boxes: { type: Array, default: () => [] },
});

const form = useForm({
    pos_register_id: props.registers?.[0]?.id || '',
    opening_cash: 0,
    float_bank_account_id: '',
    notes: '',
});

const submit = () => {
    form.post(route('pos.session.open'));
};
</script>

<template>
    <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4 selection:bg-indigo-500 selection:text-white relative overflow-hidden font-sans">
        <!-- Subtle Ambient Background Blobs matching screenshot -->
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-indigo-100/60 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-blue-100/60 rounded-full blur-3xl pointer-events-none"></div>

        <Head title="فتح جلسة نقاط بيع" />

        <div class="w-full max-w-md bg-white rounded-3xl p-8 sm:p-10 shadow-xl border border-slate-100/80 space-y-6 text-center relative z-10 animate-in fade-in zoom-in-95 duration-200">
            <!-- Al-Ostaz Logo & Title (Matching pos_ui_page_1789920504881.png) -->
            <div class="flex flex-col items-center justify-center space-y-3">
                <BrandLogo size="xl" />
                <h1 class="text-xl font-black text-slate-800 tracking-tight pt-1">
                    فتح جلسة نقاط بيع
                </h1>
            </div>

            <!-- Open Session Form -->
            <form @submit.prevent="submit" class="space-y-4 text-start">
                <div v-if="!registers?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-center text-xs text-amber-800 space-y-2">
                    <p class="font-bold">لا توجد أجهزة كاشير بعد.</p>
                    <Link v-if="$can('pos.registers.create')" :href="route('pos.registers.create')" class="inline-block px-4 py-2 rounded-lg bg-amber-500 text-white font-bold">إضافة جهاز كاشير</Link>
                    <p v-else>اطلب من مدير المنشأة إضافة جهاز من «نقاط البيع ← أجهزة الكاشير».</p>
                </div>
                <div v-else>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 text-center">
                        جهاز نقاط البيع
                    </label>
                    <select 
                        v-model="form.pos_register_id" 
                        required
                        class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 px-10 py-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-center font-medium bg-slate-50/50"
                    >
                        <option v-for="reg in registers" :key="reg.id" :value="reg.id">
                            {{ reg.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.pos_register_id" class="text-xs text-rose-600 mt-1 text-center">{{ form.errors.pos_register_id }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 text-center">العهدة الافتتاحية في الدرج ({{ $cur() }})</label>
                    <input v-model.number="form.opening_cash" type="text" inputmode="decimal" data-num step="0.01" min="0" class="w-full text-center text-sm rounded-xl border border-slate-200 px-4 py-2.5 font-mono" />
                </div>
                <div v-if="registers?.length && form.opening_cash > 0">
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 text-center">صُرفت العهدة من (اختياري)</label>
                    <SelectCreate v-model="form.float_bank_account_id" :options="boxes" entity="bank" label="name" placeholder="لم تُصرف الآن (موجودة بالدرج)" />
                    <p v-if="form.errors.float_bank_account_id" class="text-xs text-rose-600 mt-1 text-center">{{ form.errors.float_bank_account_id }}</p>
                </div>

                <!-- Action Buttons (Matching live screenshot: Purple Open, Red Cancel) -->
                <div class="space-y-2.5 pt-2">
                    <button 
                        type="submit" 
                        :disabled="form.processing || !form.pos_register_id"
                        class="w-full py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-xs hover:shadow-indigo-500/25 transition-all duration-150 disabled:opacity-50"
                    >
                        {{ form.processing ? 'جاري الفتح...' : 'فتح الجلسة' }}
                    </button>

                    <Link 
                        :href="route('dashboard')"
                        class="w-full block py-3 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-bold text-xs sm:text-sm text-center shadow-xs transition-all duration-150"
                    >
                        إلغاء
                    </Link>
                </div>
            </form>
        </div>
    </div>
</template>

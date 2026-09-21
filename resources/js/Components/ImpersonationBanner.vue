<script setup>
import { router } from '@inertiajs/vue3';
import { ArrowUturnLeftIcon, ShieldExclamationIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    impersonation: {
        type: Object,
        default: null,
    }
});

const returnToSuperAdmin = () => {
    router.post(route('super-admin.impersonate.leave'));
};
</script>

<template>
    <div 
        v-if="impersonation && impersonation.is_impersonating" 
        class="bg-amber-400 border-b border-amber-500 text-slate-900 px-4 py-2 text-sm font-medium sticky top-0 z-50 shadow-sm"
    >
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <ShieldExclamationIcon class="w-5 h-5 text-amber-900 flex-shrink-0" />
                <span>
                    <strong>وضع التدريب والدعم الفني:</strong> 
                    أنت تتصفح حالياً حساب 
                    <span class="underline decoration-2 decoration-amber-900 font-bold px-1">{{ impersonation.company_name }}</span> 
                    بصلاحية المدير العام للنظام.
                </span>
            </div>

            <button 
                @click="returnToSuperAdmin" 
                class="inline-flex items-center gap-1.5 bg-slate-900 hover:bg-black text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow transition-colors"
            >
                <ArrowUturnLeftIcon class="w-3.5 h-3.5" />
                <span>العودة للوحة التحكم الرئيسية</span>
            </button>
        </div>
    </div>
</template>

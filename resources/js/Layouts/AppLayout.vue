<script setup>
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from '../Components/AppSidebar.vue';
import AppTopBar from '../Components/AppTopBar.vue';
import ImpersonationBanner from '../Components/ImpersonationBanner.vue';
import { CheckCircleIcon, ExclamationCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline';

// Pages that list their own form errors set own-errors so they are not shown twice.
const props = defineProps({ ownErrors: { type: Boolean, default: false } });
const page = usePage();
const isSidebarOpen = ref(false);

const impersonation = computed(() => page.props.impersonation);
const flash = computed(() => page.props.flash);

const showFlash = ref(true);
const dismissFlash = () => {
    showFlash.value = false;
};

// Business-rule errors (e.g. accounting: closed period, unbalanced entry)
// are shown here even on pages whose forms do not render that field.
const generalError = computed(() => page.props.errors?.accounting || page.props.errors?.plan || null);

// Every other validation error, so a rejected save is never silent on a form
// that has no slot for that field (nested lines, hidden fields...).
const fieldErrors = computed(() => props.ownErrors ? [] : Object.entries(page.props.errors || {})
    .filter(([key]) => !['accounting', 'plan'].includes(key))
    .flatMap(([, message]) => String(message).split('\n'))
    .filter((message, i, all) => message && all.indexOf(message) === i));
const showAllErrors = ref(false);

// Subscription notice: trial ending soon, grace period, or read-only.
const subscriptionNotice = computed(() => {
    const sub = page.props.subscription;
    if (!sub) return null;
    const days = sub.ends_at ? Math.ceil((new Date(sub.ends_at) - new Date()) / 86400000) : null;
    if (sub.state === 'expired') return { tone: 'bg-rose-600', text: 'انتهى الاشتراك: البيانات متاحة للعرض فقط حتى التجديد.' };
    if (sub.state === 'grace') return { tone: 'bg-amber-500', text: 'انتهى الاشتراك وأنت في فترة السماح. جدّد لتفادي إيقاف الإدخال.' };
    if (sub.state === 'trial' && days !== null && days <= 7) return { tone: 'bg-sky-600', text: `تنتهي الفترة التجريبية خلال ${Math.max(days, 0)} يوم.` };
    return null;
});

// A new flash message after a dismissed one must be visible again.
watch(() => [flash.value?.success, flash.value?.error, generalError.value, fieldErrors.value.join('|')], () => {
    showFlash.value = true;
    showAllErrors.value = false;
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900 flex flex-col font-sans selection:bg-indigo-500 selection:text-white print:bg-white print:min-h-0 print:p-0 print:m-0 print:block">
        <!-- Super Admin Impersonation Notice Banner -->
        <ImpersonationBanner class="print:hidden" :impersonation="impersonation" />
        <div v-if="subscriptionNotice" :class="subscriptionNotice.tone" class="text-white text-xs font-bold px-4 py-2 text-center print:hidden">
            {{ subscriptionNotice.text }}
            <a v-if="$canRoute('billing.index')" :href="route('billing.index')" class="underline ms-2">إدارة الاشتراك</a>
        </div>

        <div class="flex-1 flex print:block">
            <!-- Right Navigation Sidebar (in RTL) -->
            <AppSidebar 
                class="print:hidden"
                :is-open="isSidebarOpen" 
                @close-sidebar="isSidebarOpen = false" 
            />

            <!-- Main Content Shell (Padded for 256px sidebar on the start side: Right in RTL, Left in LTR) -->
            <div class="flex-1 flex flex-col min-w-0 lg:ps-64 transition-all print:ps-0 print:p-0 print:m-0 print:block">
                <!-- Top Sticky Navbar -->
                <AppTopBar class="print:hidden" @toggle-sidebar="isSidebarOpen = !isSidebarOpen" />

                <!-- Flash Notification Banner -->
                <div v-if="showFlash && (flash?.success || flash?.error || generalError || fieldErrors.length)" class="px-4 sm:px-8 pt-4 print:hidden space-y-2">
                    <div 
                        v-if="flash?.success" 
                        class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center justify-between text-xs sm:text-sm font-semibold shadow-xs"
                    >
                        <div class="flex items-center gap-2">
                            <CheckCircleIcon class="w-5 h-5 text-emerald-600 flex-shrink-0" />
                            <span>{{ flash.success }}</span>
                        </div>
                        <button @click="dismissFlash" class="text-emerald-600 hover:text-emerald-900">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <div
                        v-if="generalError"
                        class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 flex items-center justify-between text-xs sm:text-sm font-semibold shadow-xs"
                    >
                        <div class="flex items-center gap-2">
                            <ExclamationCircleIcon class="w-5 h-5 text-red-600 flex-shrink-0" />
                            <span>{{ generalError }}</span>
                        </div>
                        <button @click="dismissFlash" class="text-red-600 hover:text-red-900">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>

                    <div v-if="fieldErrors.length" role="alert" class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs sm:text-sm shadow-xs" data-form-errors>
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-start gap-2 min-w-0">
                                <ExclamationCircleIcon class="w-5 h-5 text-amber-600 flex-shrink-0" />
                                <div class="min-w-0">
                                    <p class="font-bold">لم يتم الحفظ. راجع {{ fieldErrors.length === 1 ? 'الملاحظة التالية' : `الملاحظات التالية (${fieldErrors.length})` }}:</p>
                                    <ul class="list-disc ps-5 mt-1 space-y-0.5 break-words">
                                        <li v-for="(message, i) in (showAllErrors ? fieldErrors : fieldErrors.slice(0, 5))" :key="i">{{ message }}</li>
                                    </ul>
                                    <button v-if="fieldErrors.length > 5 && !showAllErrors" @click="showAllErrors = true" class="mt-1 font-bold underline">عرض الكل</button>
                                </div>
                            </div>
                            <button @click="dismissFlash" class="text-amber-600 hover:text-amber-900"><XMarkIcon class="w-4 h-4" /></button>
                        </div>
                    </div>

                    <div 
                        v-if="flash?.error" 
                        class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 flex items-center justify-between text-xs sm:text-sm font-semibold shadow-xs"
                    >
                        <div class="flex items-center gap-2">
                            <ExclamationCircleIcon class="w-5 h-5 text-red-600 flex-shrink-0" />
                            <span class="whitespace-pre-line">{{ flash.error }}</span>
                        </div>
                        <button @click="dismissFlash" class="text-red-600 hover:text-red-900">
                            <XMarkIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <!-- Page Content Slot -->
                <main class="flex-1 p-4 sm:p-6 lg:p-8 print:p-0 print:m-0 print:block">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>

<script setup>
import BrandLogo from '../Components/BrandLogo.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';

// wide: a larger card for long forms (company sign-up).
defineProps({ wide: { type: Boolean, default: false } });
</script>

<template>
    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50/40 to-slate-100 flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative selection:bg-indigo-500 selection:text-white font-sans">
        <!-- Top Navigation with Language Switcher -->
        <div class="absolute top-6 start-6 end-6 flex items-center justify-between">
            <BrandLogo size="sm" :showSubtitle="false" />
            <LanguageSwitcher />
        </div>

        <!-- Main Auth Container -->
        <div :class="wide ? 'sm:max-w-2xl' : 'sm:max-w-md'" class="sm:mx-auto sm:w-full px-4 mt-8 sm:mt-0">
            <div class="text-center mb-6">
                <div class="flex justify-center mb-3">
                    <BrandLogo size="lg" :showSubtitle="true" />
                </div>
            </div>

            <!-- Auth Form Card -->
            <div class="bg-white py-8 px-6 sm:px-10 shadow-[0_10px_35px_-5px_rgba(0,0,0,0.07)] rounded-3xl border border-slate-100">
                <slot />
            </div>

            <!-- Footer Copyright -->
            <div class="mt-8 text-center text-xs text-slate-400">
                <span>{{ $page.props.brand?.name }} — {{ $page.props.brand?.tagline }} © {{ new Date().getFullYear() }}</span>
            </div>
        </div>
    </div>
</template>

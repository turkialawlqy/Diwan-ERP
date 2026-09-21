<script setup>
import { usePage, router } from '@inertiajs/vue3';
import { GlobeAltIcon } from '@heroicons/vue/24/outline';
import { useI18n } from 'vue-i18n';

const page = usePage();
const { locale } = useI18n();

const toggleLanguage = () => {
    const nextLocale = page.props.locale === 'ar' ? 'en' : 'ar';
    router.post(route('locale.switch'), { locale: nextLocale }, {
        preserveScroll: true,
        onSuccess: () => {
            locale.value = nextLocale;
            document.documentElement.lang = nextLocale;
            document.documentElement.dir = nextLocale === 'ar' ? 'rtl' : 'ltr';
        }
    });
};
</script>

<template>
    <button
        @click="toggleLanguage"
        type="button"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm"
        :title="page.props.locale === 'ar' ? 'Switch to English' : 'التحويل للعربية'"
    >
        <GlobeAltIcon class="w-4 h-4 text-indigo-600" />
        <span>{{ page.props.locale === 'ar' ? 'EN' : 'العربية' }}</span>
    </button>
</template>

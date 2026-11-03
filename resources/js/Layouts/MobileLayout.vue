<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRightIcon } from '@heroicons/vue/24/outline';

/** A bare phone-first frame for the technician: a title bar, an optional back link, the flash message. */
defineProps({ title: String, back: String });
const page = usePage();
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-800" dir="rtl">
        <header class="sticky top-0 z-10 bg-indigo-700 text-white px-4 py-3 flex items-center gap-3 shadow">
            <Link v-if="back" :href="back" class="p-1 -ms-1"><ArrowRightIcon class="w-5 h-5" /></Link>
            <h1 class="font-black text-sm truncate flex-1">{{ title }}</h1>
            <Link :href="route('dashboard')" class="text-3xs text-indigo-200">النظام</Link>
        </header>
        <main class="max-w-xl mx-auto p-3 space-y-3 pb-24">
            <div v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs font-bold px-4 py-3" data-mobile-flash>{{ page.props.flash.success }}</div>
            <div v-if="page.props.errors && Object.keys(page.props.errors).length" class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs font-bold px-4 py-3" data-mobile-errors>{{ Object.values(page.props.errors).join(' · ') }}</div>
            <slot />
        </main>
    </div>
</template>

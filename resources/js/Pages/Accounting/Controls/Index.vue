<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

defineProps({ reports: Array });
</script>

<template>
    <AppLayout>
        <Head :title="$t('nav.accounting_controls')" />
        <PageHeader :title="$t('nav.accounting_controls')" :crumbs="[{ label: $t('nav.accounting') }, { label: $t('nav.accounting_controls') }]" />

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-controls>
            <Link v-for="r in reports" :key="r.key" :href="route('accounting.controls.show', r.key)" class="block bg-white border border-slate-200 rounded-2xl p-5 hover:border-indigo-300 hover:shadow-sm transition" :data-control="r.key">
                <h3 class="font-black text-slate-800 text-sm">{{ r.title }}</h3>
                <p class="text-xs text-slate-500 mt-2 leading-6">{{ r.description }}</p>
            </Link>
        </div>
    </AppLayout>
</template>

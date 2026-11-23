<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { useVisibleNav } from '@/composables/useVisibleNav';

/** «كل التقارير»: the Reports group of the sidebar as cards, only what this user may open here. */
const { t } = useI18n();
const nav = useVisibleNav();
const query = ref('');

const sections = computed(() => {
    const group = nav.value.find((g) => g.key === 'reports');
    const needle = query.value.trim().toLowerCase();
    const bySection = new Map();
    for (const child of group?.children || []) {
        if (needle && !t(child.label).toLowerCase().includes(needle)) continue;
        const key = child.section || 'nav.sec_general';
        if (!bySection.has(key)) bySection.set(key, []);
        bySection.get(key).push(child);
    }
    return [...bySection].map(([section, items]) => ({ section, items }));
});
</script>

<template>
    <AppLayout>
        <Head :title="$t('reports_hub.title')" />
        <PageHeader :title="$t('reports_hub.title')" :subtitle="$t('reports_hub.subtitle')" :crumbs="[{ label: $t('nav.reports') }, { label: $t('reports_hub.title') }]">
            <template #actions>
                <input v-model="query" type="search" :placeholder="$t('reports_hub.search')" class="rounded-xl border-slate-200 text-xs w-56" data-reports-search />
            </template>
        </PageHeader>

        <div v-if="sections.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-reports-hub>
            <div v-for="s in sections" :key="s.section" class="bg-white border border-slate-200 rounded-2xl p-4" data-reports-card>
                <h2 class="text-sm font-bold text-slate-800 mb-2">{{ $t(s.section) }}</h2>
                <ul class="space-y-1 text-xs">
                    <li v-for="item in s.items" :key="item.route">
                        <Link :href="route(item.route)" class="block px-3 py-2 rounded-lg text-slate-600 hover:bg-indigo-50 hover:text-indigo-700 font-semibold" data-report-link>{{ $t(item.label) }}</Link>
                    </li>
                </ul>
            </div>
        </div>
        <p v-else class="text-center text-slate-400 text-sm py-12">{{ $t('reports_hub.empty') }}</p>
    </AppLayout>
</template>

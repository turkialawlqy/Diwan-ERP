<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

defineProps({ role: String, node: Number, queue: Number, cursor: Object, nodes: { type: Array, default: () => [] }, conflicts: { type: Array, default: () => [] } });
const { t } = useI18n();
const reasons = computed(() => ({ id_collision: t('syncpage.reason_id_collision'), master_edited_on_branch: t('syncpage.reason_master_edited_on_branch') }));
const cell = 'px-3 py-2 text-start';
</script>

<template>
    <AppLayout>
        <Head :title="$t('syncpage.title')" />
        <PageHeader :title="$t('syncpage.title')" :crumbs="[{ label: $t('syncpage.crumb') }, { label: $t('syncpage.title') }]" />

        <div class="space-y-5 text-xs">
            <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2" data-sync-status>
                <p class="font-black text-slate-700">{{ role === 'hub' ? $t('syncpage.role_hub') : $t('syncpage.role_branch') }}</p>
                <p>{{ $t('syncpage.node') }}: <span class="font-mono" dir="ltr">{{ node }}</span></p>
                <template v-if="role === 'branch'">
                    <p>{{ $t('syncpage.queue') }}: <span class="font-mono font-bold" data-sync-queue>{{ queue }}</span></p>
                    <p v-if="cursor">{{ $t('syncpage.pushed') }}: <span class="font-mono">{{ cursor.pushed }}</span> · {{ $t('syncpage.pulled') }}: <span class="font-mono">{{ cursor.pulled }}</span></p>
                </template>
            </section>

            <section v-if="role === 'hub'" class="bg-white border border-slate-200 rounded-2xl p-5">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('syncpage.branches') }}</h3>
                <table class="w-full">
                    <thead class="text-slate-500"><tr><th :class="cell">{{ $t('syncpage.node') }}</th><th :class="cell">{{ $t('syncpage.name') }}</th><th :class="cell">{{ $t('syncpage.last_seen') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="n in nodes" :key="n.node_id" class="border-t border-slate-100">
                            <td :class="cell" class="font-mono">{{ n.node_id }}</td><td :class="cell">{{ n.name }}</td>
                            <td :class="cell" dir="ltr">{{ n.last_seen_at || $t('syncpage.never') }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="bg-white border border-slate-200 rounded-2xl p-5">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('syncpage.conflicts') }}</h3>
                <p v-if="!conflicts.length" class="text-slate-500" data-sync-no-conflicts>{{ $t('syncpage.no_conflicts') }}</p>
                <table v-else class="w-full">
                    <thead class="text-slate-500"><tr>
                        <th :class="cell">{{ $t('syncpage.node') }}</th><th :class="cell">{{ $t('syncpage.table') }}</th><th :class="cell">{{ $t('syncpage.record') }}</th>
                        <th :class="cell">{{ $t('syncpage.op') }}</th><th :class="cell">{{ $t('syncpage.reason') }}</th><th :class="cell">{{ $t('syncpage.when') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="c in conflicts" :key="c.id" class="border-t border-slate-100" data-sync-conflict>
                            <td :class="cell" class="font-mono">{{ c.node_id }}</td><td :class="cell" class="font-mono" dir="ltr">{{ c.table_name }}</td>
                            <td :class="cell" class="font-mono">{{ c.pk }}</td><td :class="cell" class="font-mono">{{ c.op }}</td>
                            <td :class="cell">{{ reasons[c.reason] || c.reason }}</td>
                            <td :class="cell" dir="ltr">{{ c.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>

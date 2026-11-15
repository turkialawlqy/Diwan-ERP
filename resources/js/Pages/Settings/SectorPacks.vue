<script setup>
import { useI18n } from 'vue-i18n';
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
const { t } = useI18n();

const props = defineProps({ packs: Array, branchId: Number, branches: Array, canShare: Boolean });
const branch = ref(props.branchId ?? (props.canShare ? '' : props.branches[0]?.id ?? ''));
const pick = () => router.get(route('settings.sector-packs'), branch.value ? { branch: branch.value } : {}, { preserveState: true, preserveScroll: true });
const open = reactive({});
const apply = (p) => {
    if (confirm(t('set2_settings_sectorpacks.enable_the_n_pack_ready_services', { n0: p.label }))) router.post(route('settings.sector-packs.apply', p.key), { branch_id: branch.value || null }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('set2_settings_sectorpacks.sector_packs')" />
        <PageHeader :title="$t('set2_settings_sectorpacks.sector_packs')" :subtitle="$t('set2_settings_sectorpacks.a_ready_setup_for_your_business')" :crumbs="[{ label: $t('set2_settings_sectorpacks.settings'), href: route('settings.index') }, { label: $t('set2_settings_sectorpacks.sector_packs') }]" />
        <div v-if="branches.length > 1 || canShare" class="mb-4 flex items-center gap-2 text-xs" data-pack-branch>
            <span class="font-bold text-slate-700">{{ $t('set2_settings_sectorpacks.the_pack_applies_to') }}</span>
            <select v-model="branch" @change="pick" class="rounded-xl border-slate-200 text-xs"><option v-if="canShare" value="">{{ $t('set2_settings_sectorpacks.all_branches_shared_catalog') }}</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name_ar || b.name_en }}</option></select>
            <span class="text-slate-500">{{ $t('set2_settings_sectorpacks.a_branch_s_services_and_job_type') }}</span>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div v-for="p in packs" :key="p.key" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" :data-pack="p.key">
                <div class="flex items-start justify-between gap-3">
                    <div><h2 class="font-black text-sm text-slate-900">{{ p.label }}</h2><p class="text-slate-500 mt-1">{{ p.description }}</p></div>
                    <span v-if="p.applied" class="shrink-0 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold" data-applied>{{ $t('set2_settings_sectorpacks.enabled') }}</span>
                </div>
                <p class="text-slate-500">{{ $t('set2_settings_sectorpacks.enables') }} <b class="text-slate-700">{{ p.modules.join($t('set2_settings_sectorpacks.text')) }}</b></p>
                <button type="button" class="text-indigo-600 font-bold" @click="open[p.key] = !open[p.key]" data-toggle-items>{{ open[p.key] ? $t('set2_settings_sectorpacks.hide_the_content') : $t('set2_settings_sectorpacks.show_the_content_n', { n0: p.items.length }) }}</button>
                <ul v-if="open[p.key]" class="space-y-0.5 max-h-56 overflow-y-auto border-t border-slate-100 pt-2">
                    <li v-for="(i, n) in p.items" :key="n" class="flex justify-between gap-2"><span><span class="text-slate-400">{{ i.kind }}:</span> {{ i.name }}</span><span :class="i.exists ? 'text-slate-400' : 'text-emerald-700 font-bold'">{{ i.exists ? $t('set2_settings_sectorpacks.exists') : $t('set2_settings_sectorpacks.new') }}</span></li>
                </ul>
                <button v-if="$can('admin.settings.edit')" type="button" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" @click="apply(p)" data-apply>{{ p.applied ? $t('set2_settings_sectorpacks.reapply_adds_only_what_is_missin') : $t('set2_settings_sectorpacks.enable_the_pack') }}</button>
            </div>
        </div>
    </AppLayout>
</template>

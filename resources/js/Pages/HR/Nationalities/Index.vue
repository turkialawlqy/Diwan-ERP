<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import { PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ nationalities: Array, stats: Object, filters: Object });
const f = reactive({ search: props.filters.search || '', status: props.filters.status || 'active' });
const apply = () => router.get(route('hr.nationalities.index'), { search: f.search || undefined, status: f.status }, { preserveState: true, replace: true });
// Regional-indicator letters make the flag emoji of an ISO2 code.
const flag = (code) => String.fromCodePoint(...[...String(code).toUpperCase()].map((c) => 127397 + c.charCodeAt(0)));
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_nationalities_index.nationalities')" />
        <PageHeader :title="$t('hr_nationalities_index.nationalities')" :subtitle="$t('hr_nationalities_index.the_list_of_nationalities_availa')" :crumbs="[{ label: $t('hr2_hr_nationalities_index.human_resources') }, { label: $t('hr2_hr_nationalities_index.nationalities') }]">
            <template #actions>
                <Link v-if="$can('hr.nationalities.create')" :href="route('hr.nationalities.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('hr_nationalities_index.add_a_nationality') }}</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
            <StatCard :label="$t('hr_nationalities_index.total_registered_nationalities')" :value="stats.total" />
            <StatCard :label="$t('hr_nationalities_index.active_nationalities')" :value="stats.active" />
            <StatCard :label="$t('hr_nationalities_index.disabled_nationalities')" :value="stats.inactive" />
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex flex-wrap gap-2 text-xs">
            <input v-model="f.search" @input="apply" :placeholder="$t('hr_nationalities_index.search_by_name_or_iso2')" class="flex-1 min-w-[12rem] rounded-xl border-slate-200 text-xs" />
            <select v-model="f.status" @change="apply" class="rounded-xl border-slate-200 text-xs">
                <option value="active">{{ $t('hr_nationalities_index.active_only') }}</option><option value="inactive">{{ $t('hr_nationalities_index.disabled_only') }}</option><option value="all">{{ $t('hr_nationalities_index.all') }}</option>
            </select>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr>
                    <th class="px-4 py-2 text-start w-16">{{ $t('hr_nationalities_index.order') }}</th><th class="px-4 py-2 text-start w-16">{{ $t('hr_nationalities_index.flag') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_nationalities_index.nationality_in_arabic') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_nationalities_index.in_english') }}</th>
                    <th class="px-4 py-2 text-start">ISO2</th><th class="px-4 py-2 text-start">{{ $t('hr_nationalities_index.number_of_employees') }}</th><th class="px-4 py-2 text-start">{{ $t('hr_nationalities_index.status') }}</th><th class="px-4 py-2 w-16"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="(n, i) in nationalities" :key="n.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 text-slate-400">{{ i + 1 }}</td>
                        <td class="px-4 py-2 text-lg">{{ flag(n.code) }}</td>
                        <td class="px-4 py-2 font-bold">{{ n.name_ar }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ n.name_en }}</td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 font-mono font-bold">{{ n.code }}</span></td>
                        <td class="px-4 py-2 font-mono">{{ n.employees }}</td>
                        <td class="px-4 py-2"><span :class="n.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'" class="px-2 py-0.5 rounded-full border text-[11px] font-bold">{{ n.is_active ? $t('hr2_hr_nationalities_index.active') : $t('hr2_hr_nationalities_index.disabled') }}</span></td>
                        <td class="px-4 py-2 text-end"><Link v-if="$can('hr.nationalities.edit')" :href="route('hr.nationalities.edit', n.id)" class="text-slate-400 hover:text-indigo-600"><PencilSquareIcon class="w-4 h-4" /></Link></td>
                    </tr>
                    <tr v-if="!nationalities.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ $t('hr_nationalities_index.no_results') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

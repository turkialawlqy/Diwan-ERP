<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { PaperClipIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { today } from '@/utils/format';

/** «تحضير المعدات»: the day's sheet — every working equipment, prepared / needs attention / still missing. */
const props = defineProps({ date: String, rows: Array, counts: Object });

const changeDate = (value) => router.get(route('equipment.preparations.index'), { date: value }, { preserveState: false });
const remove = (p) => route('equipment.preparations.destroy', p.id);
</script>

<template>
    <AppLayout>
        <Head :title="$t('eq_prep.title')" />
        <PageHeader :title="$t('eq_prep.title')" :subtitle="$t('eq_prep.subtitle')" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: $t('eq_prep.title') }]">
            <template #actions>
                <label class="text-xs font-bold text-slate-600 flex items-center gap-2">{{ $t('ui.date') }} <DatePicker :model-value="date" :max="today()" @change="changeDate($event.target.value)" class="rounded-xl border-slate-200 text-xs" /></label>
                <Link v-if="$can('equipment.preparations.create')" :href="route('equipment.preparations.create', { date })" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700" data-prep-new>+ {{ $t('eq_prep.new') }}</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard :label="$t('eq_prep.total')" :value="counts.total" />
            <StatCard :label="$t('eq_prep.ready')" :value="counts.ready" tone="emerald" />
            <StatCard :label="$t('eq_prep.needs_attention')" :value="counts.needs_attention" tone="amber" />
            <StatCard :label="$t('eq_prep.missing')" :value="counts.missing" :tone="counts.missing ? 'rose' : 'emerald'" />
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs" data-prep-sheet>
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">{{ $t('eq_prep.equipment') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('ui.status') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('eq_prep.operator') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('eq_prep.cards') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('eq_prep.notes') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.equipment.id" class="border-t border-slate-100" data-prep-row>
                        <td class="px-3 py-2"><Link :href="route('equipment.show', r.equipment.id)" class="font-bold text-slate-800 hover:text-indigo-700">{{ r.equipment.code }} — {{ r.equipment.name }}</Link></td>
                        <td class="px-3 py-2">
                            <span v-if="!r.preparation" class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-600 font-bold border border-rose-100">{{ $t('eq_prep.not_prepared') }}</span>
                            <span v-else-if="r.preparation.status === 'ready'" class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold border border-emerald-100">{{ $t('eq_prep.ready_one') }}</span>
                            <span v-else class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-bold border border-amber-100">{{ $t('eq_prep.needs_attention_one') }}</span>
                            <span v-if="r.preparation" class="text-slate-400 font-mono ms-2">{{ r.preparation.number }}</span>
                        </td>
                        <td class="px-3 py-2 text-slate-600">{{ r.preparation?.operator || '' }}</td>
                        <td class="px-3 py-2"><span v-if="r.preparation" class="inline-flex items-center gap-1 text-slate-500"><PaperClipIcon class="w-4 h-4" /> {{ r.preparation.files }}</span></td>
                        <td class="px-3 py-2 text-slate-500 max-w-xs truncate">{{ r.preparation?.notes || '' }}</td>
                        <td class="px-3 py-2 text-end whitespace-nowrap">
                            <Link v-if="!r.preparation && $can('equipment.preparations.create')" :href="route('equipment.preparations.create', { equipment_id: r.equipment.id, date })" class="px-3 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold hover:bg-indigo-100" data-prep-do>{{ $t('eq_prep.prepare') }}</Link>
                            <RowActions v-else-if="r.preparation" :edit="$can('equipment.preparations.edit') ? route('equipment.preparations.edit', r.preparation.id) : null" :remove="$can('equipment.preparations.delete') ? remove(r.preparation) : null" :confirm="$t('eq_prep.confirm_delete', { number: r.preparation.number })" />
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ $t('eq_prep.no_equipment') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

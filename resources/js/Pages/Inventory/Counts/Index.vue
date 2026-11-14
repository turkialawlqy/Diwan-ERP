<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Field from '@/Components/UI/Field.vue';
import { date, today } from '@/utils/format';
const { t } = useI18n();

defineProps({ counts: Object, warehouses: Array });
const form = useForm({ warehouse_id: '', count_date: today(), count_type: 'full', notes: '' });
const start = () => form.post(route('inventory.counts.store'));
const statusLabel = computed(() => ({ draft: [t('i_counts_index.st_draft'), 'text-amber-600'], approved: [t('i_counts_index.st_approved'), 'text-emerald-600'], cancelled: [t('i_common.cancelled'), 'text-slate-400'] }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('i_counts_index.stock_count')" />
        <PageHeader :title="$t('i_counts_index.stock_counting')" :subtitle="$t('i_counts_index.a_full_count_or_a_spot_count_of')" :crumbs="[{ label: 'المستودعات' }, { label: 'الجرد' }]" />

        <form v-if="$can('inventory.counts.create')" @submit.prevent="start" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-5 gap-3 text-xs mb-4">
            <Field :label="$t('i_counts_index.warehouse')" :error="form.errors.warehouse_id"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required /></Field>
            <Field :label="$t('i_counts_index.count_date')"><DatePicker v-model="form.count_date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('i_counts_index.count_type')"><select v-model="form.count_type" class="w-full rounded-xl border-slate-200 text-xs"><option value="full">{{ $t('i_counts_index.full_warehouse_count') }}</option><option value="partial">{{ $t('i_counts_index.spot_count_specific_items') }}</option></select></Field>
            <Field :label="$t('i_counts_index.notes')"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <div class="flex items-end"><button class="w-full rounded-xl bg-indigo-600 text-white font-bold py-2" :disabled="form.processing">{{ $t('i_counts_index.start_the_count') }}</button></div>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">{{ $t('i_counts_index.count_number') }}</th><th class="text-start px-4 py-2">{{ $t('i_counts_index.warehouse') }}</th><th class="px-4 py-2">{{ $t('ui.date') }}</th><th class="px-4 py-2">{{ $t('i_counts_index.type') }}</th><th class="px-4 py-2">{{ $t('i_counts_index.items') }}</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="c in counts.data" :key="c.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono">{{ c.count_number }}</td>
                        <td class="px-4 py-2">{{ c.warehouse?.name_ar }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(c.count_date) }}</td>
                        <td class="px-4 py-2 text-center">{{ c.count_type === 'full' ? $t('i_counts_index.full') : $t('i_counts_index.spot') }}</td>
                        <td class="px-4 py-2 text-center">{{ c.items_count }}</td>
                        <td class="px-4 py-2 text-center"><span :class="statusLabel[c.status][1]" class="font-bold">{{ statusLabel[c.status][0] }}</span></td>
                        <td class="px-4 py-2 text-end"><Link :href="route('inventory.counts.show', c.id)" class="text-indigo-600 font-bold">{{ $t('i_counts_index.open') }}</Link></td>
                    </tr>
                    <tr v-if="!counts.data.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="counts" />
        </div>
    </AppLayout>
</template>

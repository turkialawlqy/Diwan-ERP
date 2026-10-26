<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import Field from '@/Components/UI/Field.vue';
import { date } from '@/utils/format';

defineProps({ counts: Object, warehouses: Array });
const form = useForm({ warehouse_id: '', count_date: new Date().toISOString().substring(0, 10), count_type: 'full', notes: '' });
const start = () => form.post(route('inventory.counts.store'));
const statusLabel = { draft: ['قيد الجرد', 'text-amber-600'], approved: ['معتمد', 'text-emerald-600'], cancelled: ['ملغى', 'text-slate-400'] };
</script>

<template>
    <AppLayout>
        <Head title="الجرد" />
        <PageHeader title="جرد المخزون" subtitle="جرد كامل أو جرد مفاجئ لأصناف محددة، ثم اعتماد الفروقات وترحيلها" :crumbs="[{ label: 'المستودعات' }, { label: 'الجرد' }]" />

        <form v-if="$can('inventory.counts.create')" @submit.prevent="start" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-5 gap-3 text-xs mb-4">
            <Field label="المستودع" :error="form.errors.warehouse_id"><select v-model="form.warehouse_id" required class="w-full rounded-xl border-slate-200 text-xs"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
            <Field label="تاريخ الجرد"><input v-model="form.count_date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="نوع الجرد"><select v-model="form.count_type" class="w-full rounded-xl border-slate-200 text-xs"><option value="full">جرد كامل للمستودع</option><option value="partial">جرد مفاجئ (أصناف محددة)</option></select></Field>
            <Field label="ملاحظات"><input v-model="form.notes" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <div class="flex items-end"><button class="w-full rounded-xl bg-indigo-600 text-white font-bold py-2" :disabled="form.processing">بدء الجرد</button></div>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">رقم الجرد</th><th class="text-start px-4 py-2">المستودع</th><th class="px-4 py-2">{{ $t('ui.date') }}</th><th class="px-4 py-2">النوع</th><th class="px-4 py-2">الأصناف</th><th class="px-4 py-2">{{ $t('ui.status') }}</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="c in counts.data" :key="c.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-mono">{{ c.count_number }}</td>
                        <td class="px-4 py-2">{{ c.warehouse?.name_ar }}</td>
                        <td class="px-4 py-2 text-center font-mono">{{ date(c.count_date) }}</td>
                        <td class="px-4 py-2 text-center">{{ c.count_type === 'full' ? 'كامل' : 'مفاجئ' }}</td>
                        <td class="px-4 py-2 text-center">{{ c.items_count }}</td>
                        <td class="px-4 py-2 text-center"><span :class="statusLabel[c.status][1]" class="font-bold">{{ statusLabel[c.status][0] }}</span></td>
                        <td class="px-4 py-2 text-end"><Link :href="route('inventory.counts.show', c.id)" class="text-indigo-600 font-bold">فتح</Link></td>
                    </tr>
                    <tr v-if="!counts.data.length"><td colspan="7" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="counts" />
        </div>
    </AppLayout>
</template>

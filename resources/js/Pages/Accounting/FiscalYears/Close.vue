<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';
import { amount } from '@/utils/format';

const props = defineProps({ year: Object, branches: Array, warnings: Array, ended: Boolean });
const mode = ref('all');
const pending = computed(() => props.branches.filter((b) => !b.closed));
const blocked = computed(() => pending.value.filter((b) => !b.ready));
const ready = computed(() => pending.value.filter((b) => b.ready));
const canClose = computed(() => props.ended && (mode.value === 'all' ? !blocked.value.length && pending.value.length >= 0 : ready.value.length > 0));
const submit = () => {
    if (confirm(`إقفال السنة المالية ${props.year.name}؟ سيُرحَّل صافي النتيجة لكل فرع إلى الأرباح المحتجزة.`)) {
        router.post(route('accounting.fiscal-years.close', props.year.id), { mode: mode.value });
    }
};
</script>

<template>
    <AppLayout>
        <Head title="إقفال السنة المالية" />
        <PageHeader :title="`إقفال السنة المالية ${year.name}`" :subtitle="`${date(year.start_date)} → ${date(year.end_date)}`" :crumbs="[{ label: 'المحاسبة' }, { label: 'السنوات المالية', href: route('accounting.fiscal-years.index') }, { label: 'إقفال' }]">
            <template #actions>
                <Link v-if="$can('accounting.controls.view')" :href="route('accounting.controls.show', { report: 'closing-checklist', fiscal_year_id: year.id })" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-600 hover:text-indigo-600" data-closing-checklist-link>{{ $t('controls.closing_checklist') }}</Link>
            </template>
        </PageHeader>

        <p v-if="!ended" class="mb-4 border border-amber-200 bg-amber-50 text-amber-700 rounded-xl px-4 py-3 text-xs font-bold">لا يمكن إقفال سنة قبل انتهائها ({{ date(year.end_date) }}).</p>
        <div v-for="w in warnings" :key="w" class="mb-3 border border-amber-200 bg-amber-50 text-amber-800 rounded-xl px-4 py-3 text-xs" data-close-warning>⚠ {{ w }}</div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-4">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">الفرع</th><th class="px-4 py-2">قيود مسودة</th><th class="text-end px-4 py-2">صافي النتيجة</th><th class="px-4 py-2">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="b in branches" :key="b.branch_id ?? 'none'" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-bold">{{ b.name }}</td>
                        <td class="px-4 py-2 text-center font-mono" :class="b.drafts ? 'text-rose-600 font-bold' : ''">{{ b.drafts }}</td>
                        <td class="px-4 py-2 text-end font-mono" :class="b.net >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ amount(b.net) }}</td>
                        <td class="px-4 py-2 text-center">
                            <span v-if="b.closed" class="text-slate-500 font-bold">مُقفل</span>
                            <span v-else-if="b.ready" class="text-emerald-600 font-bold">✓ جاهز</span>
                            <span v-else class="text-rose-600 font-bold">✗ فيه قيود مسودة</span>
                        </td>
                    </tr>
                    <tr v-if="!branches.length"><td colspan="4" class="py-8 text-center text-slate-400">لا توجد حركات في هذه السنة</td></tr>
                </tbody>
            </table>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs space-y-3">
            <label class="flex items-start gap-2"><input type="radio" v-model="mode" value="all" class="mt-0.5" /><span><b>الكل أو لا شيء</b> — يُقفل النظام إن اجتازت كل الفروع الفحص، وإلا لا يُقفل أي فرع.</span></label>
            <label class="flex items-start gap-2"><input type="radio" v-model="mode" value="passed" class="mt-0.5" /><span><b>إقفال الفروع الجاهزة فقط</b> — تُقفل الفروع التي اجتازت وتبقى السنة مفتوحة حتى يُقفل الباقي.</span></label>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="button" :disabled="!canClose" @click="submit" class="px-5 py-2 rounded-xl bg-amber-600 text-white font-bold disabled:opacity-40" data-confirm-close>إقفال السنة</button>
                <Link :href="route('accounting.fiscal-years.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">رجوع</Link>
            </div>
        </div>
    </AppLayout>
</template>

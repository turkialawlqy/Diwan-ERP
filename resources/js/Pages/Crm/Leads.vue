<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';

defineProps({ board: Array, users: Array, sources: Object, filters: Object, stats: Object });
const open = ref(false);
const form = useForm({ name: '', company_name: '', phone: '', email: '', source: 'call', expected_value: 0, next_follow_up: '', assigned_to: '', notes: '' });
const dragging = ref(null);
const drop = (status) => { if (dragging.value && dragging.value.status !== status) router.post(route('crm.leads.move', dragging.value.id), { status }, { preserveScroll: true }); dragging.value = null; };
const overdue = (d) => d && new Date(d) <= new Date();
</script>

<template>
    <AppLayout>
        <Head title="العملاء المحتملون" />
        <PageHeader title="العملاء المحتملون والفرص" subtitle="اسحب البطاقة لتغيير المرحلة" :crumbs="[{ label: 'CRM' }, { label: 'العملاء المحتملون' }]">
            <template #actions><button v-if="$can('crm.leads.create')" @click="open = true" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> عميل محتمل</button></template>
        </PageHeader>
        <div class="grid grid-cols-3 gap-3 mb-4">
            <StatCard label="قيمة الفرص المفتوحة" :value="money(stats.open_value)" tone="indigo" />
            <StatCard label="تم كسبها هذا الشهر" :value="stats.won_this_month" tone="emerald" />
            <StatCard label="متابعات مستحقة" :value="stats.due_follow_ups" tone="amber" />
        </div>
        <div class="flex gap-3 overflow-x-auto pb-3 text-xs">
            <div v-for="col in board" :key="col.status" class="min-w-60 w-60 bg-slate-100 rounded-2xl p-2" @dragover.prevent @drop="drop(col.status)">
                <div class="flex justify-between px-1 mb-2"><b>{{ col.label }} ({{ col.leads.length }})</b><span class="font-mono text-slate-500">{{ money(col.value) }}</span></div>
                <Link v-for="l in col.leads" :key="l.id" :href="route('crm.leads.show', l.id)" draggable="true" @dragstart="dragging = l" class="block bg-white rounded-xl p-2 mb-2 border border-slate-200 hover:border-indigo-300">
                    <b>{{ l.name }}</b><div class="text-slate-500">{{ l.company_name }}</div>
                    <div class="flex justify-between mt-1"><span class="font-mono">{{ money(l.expected_value) }}</span><span :class="overdue(l.next_follow_up) ? 'text-rose-600 font-bold' : 'text-slate-400'" class="font-mono">{{ date(l.next_follow_up) }}</span></div>
                    <div class="text-slate-400">{{ l.assignee?.name }}</div>
                </Link>
            </div>
        </div>
        <Dialog :show="open" title="عميل محتمل جديد" @close="open = false">
            <div class="grid grid-cols-2 gap-3">
                <Field label="الاسم" :error="form.errors.name"><input v-model="form.name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الشركة"><input v-model="form.company_name" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الجوال"><input v-model="form.phone" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="البريد" :error="form.errors.email"><input v-model="form.email" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المصدر"><select v-model="form.source" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(l, k) in sources" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="القيمة المتوقعة"><input v-model="form.expected_value" type="number" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="موعد المتابعة"><input v-model="form.next_follow_up" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="المسؤول"><select v-model="form.assigned_to" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
                <Field label="ملاحظات" class="col-span-2"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
            </div>
            <template #footer><button @click="form.post(route('crm.leads.store'))" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button></template>
        </Dialog>
    </AppLayout>
</template>

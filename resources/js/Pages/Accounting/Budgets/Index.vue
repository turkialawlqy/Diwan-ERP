<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';

const props = defineProps({ budgets: Array, years: Array });
const form = useForm({ name: '', fiscal_year_id: props.years[0]?.id ?? '' });
const create = () => form.post(route('accounting.budgets.store'));
</script>

<template>
    <AppLayout>
        <Head title="الموازنات التقديرية" />
        <PageHeader title="الموازنات التقديرية" subtitle="تخطيط الإيرادات والمصروفات الشهرية ومقارنتها بالفعلي" :crumbs="[{ label: 'المحاسبة' }, { label: 'الموازنات' }]" />

        <form v-if="$can('accounting.budgets.create')" @submit.prevent="create" class="bg-white border border-slate-200 rounded-2xl p-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-xs mb-4">
            <Field label="اسم الموازنة" :error="form.errors.name"><input v-model="form.name" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="السنة المالية" :error="form.errors.fiscal_year_id"><select v-model="form.fiscal_year_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select></Field>
            <div class="flex items-end"><button class="w-full rounded-xl bg-indigo-600 text-white font-bold py-2">إنشاء موازنة</button></div>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <Link v-for="b in budgets" :key="b.id" :href="route('accounting.budgets.show', b.id)" class="bg-white border border-slate-200 rounded-2xl p-4 hover:border-indigo-300">
                <p class="font-black text-slate-800">{{ b.name }}</p>
                <p class="text-xs text-slate-500 mt-1">السنة المالية {{ b.fiscal_year?.name }} · <span :class="b.status === 'approved' ? 'text-emerald-600' : 'text-amber-600'">{{ b.status === 'approved' ? 'معتمدة' : 'مسودة' }}</span></p>
            </Link>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ types: Array });
const forms = Object.fromEntries(props.types.map((t) => [t.key, useForm({ file: null, opening_date: null })]));
const formFor = (key) => forms[key];
// Row errors arrive as one message, a line each.
const lines = (...messages) => messages.filter(Boolean).flatMap((m) => String(m).split('\n'));

// Go-live date shared by every opening balance posted from this page.
const openingDate = ref(new Date().toISOString().slice(0, 10));
const upload = (key) => {
    const form = formFor(key);
    form.opening_date = openingDate.value;
    form.post(route('data-transfer.import', key), { forceFormData: true, preserveScroll: true });
};

// Suggested order for a company moving onto the system.
const steps = [
    ['customers', 'العملاء بأرصدتهم (opening_balance: موجب = مدين لنا، سالب = دفعة مقدمة)'],
    ['suppliers', 'الموردون بأرصدتهم (opening_balance: موجب = دائن لهم)'],
    ['products', 'الأصناف بكمياتها (opening_quantity و opening_cost و warehouse_code)'],
    ['opening_balances', 'ميزان المراجعة لباقي الحسابات (البنوك، الأصول، القروض، رأس المال...)'],
];
const openingSteps = computed(() => steps.map(([key, hint]) => ({ hint, type: props.types.find((t) => t.key === key) })).filter((s) => s.type?.can_import));
const masterTypes = computed(() => props.types.filter((t) => !t.opening));
</script>

<template>
    <AppLayout>
        <Head title="استيراد وتصدير" />
        <PageHeader title="استيراد وتصدير البيانات" subtitle="CSV أو Excel؛ يُتحقق من كل الصفوف قبل الحفظ، والسجلات الموجودة (بالرمز) تُحدّث" :crumbs="[{ label: 'الإعدادات' }, { label: 'استيراد وتصدير' }]" />

        <section v-if="openingSteps.length" class="bg-indigo-50/60 border border-indigo-100 rounded-2xl p-4 mb-4 text-xs space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="font-black text-indigo-900">الأرصدة الافتتاحية (بدء التشغيل)</h2>
                    <p class="text-indigo-800/80 mt-1">تُرحَّل الأرصدة مقابل حساب «أرصدة افتتاحية» بتاريخ بدء التشغيل، وللسجلات الجديدة فقط. بعد الخطوات الأربع يجب أن يصبح رصيد حساب الأرصدة الافتتاحية مساوياً لحقوق الملكية الافتتاحية.</p>
                </div>
                <label class="flex items-center gap-2 font-bold text-indigo-900">تاريخ بدء التشغيل
                    <input v-model="openingDate" type="date" class="rounded-xl border-indigo-200 text-xs" />
                </label>
            </div>
            <ol class="space-y-2">
                <li v-for="(s, i) in openingSteps" :key="s.type.key" class="bg-white border border-indigo-100 rounded-xl p-3 flex flex-wrap items-center gap-3">
                    <span class="w-6 h-6 rounded-full bg-indigo-600 text-white font-black flex items-center justify-center">{{ i + 1 }}</span>
                    <div class="flex-1 min-w-[12rem]">
                        <p class="font-bold text-slate-700">{{ s.type.label }}</p>
                        <p class="text-slate-500">{{ s.hint }}</p>
                    </div>
                    <a :href="route('data-transfer.template', s.type.key)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">القالب</a>
                    <input type="file" accept=".csv,.txt,.xlsx,.xls" @input="formFor(s.type.key).file = $event.target.files[0]" class="text-xs max-w-[14rem]" />
                    <button @click="upload(s.type.key)" :disabled="!formFor(s.type.key).file || formFor(s.type.key).processing" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-40">استيراد</button>
                    <ul v-if="formFor(s.type.key).errors.file || formFor(s.type.key).errors.opening_date" class="basis-full text-rose-600 list-disc ps-4">
                        <li v-for="(e, j) in lines(formFor(s.type.key).errors.file, formFor(s.type.key).errors.opening_date)" :key="j">{{ e }}</li>
                    </ul>
                </li>
            </ol>
        </section>

        <div class="grid md:grid-cols-2 gap-4 text-xs">
            <div v-for="t in masterTypes" :key="t.key" class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700">{{ t.label }}</h3>
                <p class="text-slate-400 font-mono mt-1 break-words" dir="ltr">{{ t.columns.join(', ') }}</p>
                <div class="flex flex-wrap gap-2 mt-3">
                    <a v-if="t.can_export" :href="route('data-transfer.export', t.key)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">تصدير CSV</a>
                    <a v-if="t.can_import" :href="route('data-transfer.template', t.key)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">قالب الاستيراد</a>
                </div>
                <div v-if="t.can_import" class="flex flex-wrap items-center gap-2 mt-3">
                    <input type="file" accept=".csv,.txt,.xlsx,.xls" @input="formFor(t.key).file = $event.target.files[0]" class="text-xs max-w-full" />
                    <button @click="upload(t.key)" :disabled="!formFor(t.key).file || formFor(t.key).processing" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-40">استيراد</button>
                </div>
                <ul v-if="formFor(t.key).errors.file" class="mt-2 text-rose-600 list-disc ps-4"><li v-for="(e, i) in lines(formFor(t.key).errors.file)" :key="i">{{ e }}</li></ul>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ patients: Object, filters: Object });
const search = ref(props.filters.search || '');
const go = () => router.get(route('clinic.patients.index'), search.value ? { search: search.value } : {}, { preserveState: true, replace: true });
</script>

<template>
    <AppLayout>
        <Head title="ملفات المرضى" />
        <PageHeader title="ملفات المرضى" subtitle="بيانات طبية حسّاسة: متاحة لمن يملك صلاحيتها فقط" :crumbs="[{ label: 'العيادة' }, { label: 'ملفات المرضى' }]">
            <template #actions><Link v-if="$can('clinic.patients.create')" :href="route('clinic.patients.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-patient><PlusIcon class="w-4 h-4" /> ملف مريض جديد</Link></template>
        </PageHeader>
        <div class="mb-3"><input v-model="search" @keyup.enter="go" placeholder="رقم الملف أو الاسم أو الجوال" class="w-full md:w-80 rounded-xl border-slate-200 text-xs" /></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">رقم الملف</th><th class="px-4 py-2 text-start">المريض</th><th class="px-4 py-2 text-start">الجوال</th><th class="px-4 py-2 text-start">فصيلة الدم</th><th class="px-4 py-2 w-16"></th></tr></thead>
            <tbody>
                <tr v-for="p in patients.data" :key="p.id" class="border-t border-slate-100" :data-patient="p.file_number">
                    <td class="px-4 py-2"><Link :href="route('clinic.patients.show', p.id)" class="font-mono font-bold text-indigo-600">{{ p.file_number }}</Link></td><td class="px-4 py-2 font-bold">{{ p.customer?.name }}</td>
                    <td class="px-4 py-2 font-mono" dir="ltr">{{ p.customer?.phone }}</td><td class="px-4 py-2 font-mono">{{ p.blood_type || '—' }}</td>
                    <td class="px-4 py-2 text-end"><RowActions :view="route('clinic.patients.show', p.id)" :edit="$can('clinic.patients.edit') ? route('clinic.patients.edit', p.id) : null" /></td>
                </tr>
                <tr v-if="!patients.data.length"><td colspan="5" class="py-10 text-center text-slate-400">لا ملفات بعد.</td></tr>
            </tbody></table></div>
        <Pagination :paginator="patients" />
    </AppLayout>
</template>

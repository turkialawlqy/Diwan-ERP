<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import RowActions from '@/Components/UI/RowActions.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ vehicles: Object, filters: Object });
const search = ref(props.filters.search || '');
const due = ref(!!props.filters.due);
const go = () => router.get(route('workshop.vehicles.index'), { ...(search.value ? { search: search.value } : {}), ...(due.value ? { due: 1 } : {}) }, { preserveState: true, replace: true });
const d = (v) => (v ? String(v).slice(0, 10) : '—');
</script>

<template>
    <AppLayout>
        <Head title="مركبات العملاء" />
        <PageHeader title="مركبات العملاء" subtitle="بطاقة كل سيارة وموعد صيانتها القادم" :crumbs="[{ label: 'الورشة' }, { label: 'المركبات' }]">
            <template #actions><Link v-if="$can('workshop.vehicles.create')" :href="route('workshop.vehicles.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-vehicle><PlusIcon class="w-4 h-4" /> مركبة جديدة</Link></template>
        </PageHeader>
        <div class="mb-3 flex flex-wrap items-center gap-3 text-xs"><input v-model="search" @keyup.enter="go" placeholder="اللوحة أو الهيكل أو العميل" class="w-64 rounded-xl border-slate-200 text-xs" /><label class="flex items-center gap-1.5"><input v-model="due" type="checkbox" class="rounded border-slate-300" @change="go" /> صيانتها خلال 30 يومًا</label></div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto"><table class="w-full text-xs">
            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">اللوحة</th><th class="px-4 py-2 text-start">المركبة</th><th class="px-4 py-2 text-start">العميل</th><th class="px-4 py-2 text-start">العداد</th><th class="px-4 py-2 text-start">الصيانة القادمة</th><th class="px-4 py-2 w-16"></th></tr></thead>
            <tbody>
                <tr v-for="v in vehicles.data" :key="v.id" class="border-t border-slate-100" :data-vehicle="v.plate">
                    <td class="px-4 py-2"><Link :href="route('workshop.vehicles.show', v.id)" class="font-mono font-bold text-indigo-600">{{ v.plate }}</Link></td><td class="px-4 py-2">{{ [v.make, v.model, v.year].filter(Boolean).join(' ') }}</td>
                    <td class="px-4 py-2 font-bold">{{ v.customer?.name }}</td><td class="px-4 py-2 font-mono">{{ v.odometer }}</td><td class="px-4 py-2 font-mono">{{ d(v.next_service_date) }}</td>
                    <td class="px-4 py-2 text-end"><RowActions :view="route('workshop.vehicles.show', v.id)" :edit="$can('workshop.vehicles.edit') ? route('workshop.vehicles.edit', v.id) : null" :remove="$can('workshop.vehicles.delete') ? route('workshop.vehicles.destroy', v.id) : null" :confirm="`حذف المركبة ${v.plate}؟`" /></td>
                </tr>
                <tr v-if="!vehicles.data.length"><td colspan="6" class="py-10 text-center text-slate-400">لا مركبات.</td></tr>
            </tbody></table></div>
        <Pagination :paginator="vehicles" />
    </AppLayout>
</template>

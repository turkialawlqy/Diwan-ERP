<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import MaintenanceStatus from '@/Components/Equipment/MaintenanceStatus.vue';
import { money, qty, date } from '@/utils/format';

const props = defineProps({ due: Array, counts: Object, open: Array, parts: Array, month: Object });
const tiles = [
    ['overdue', 'صيانة متأخرة', 'text-rose-600'],
    ['due_soon', 'تستحق قريباً', 'text-amber-600'],
    ['in_workshop', 'معدات في الورشة', 'text-indigo-600'],
    ['parts_short', 'أصناف رصيدها لا يكفي', 'text-rose-600'],
    ['out_of_service', 'خارج الخدمة', 'text-slate-500'],
];
const openOrder = (equipmentId) => router.get(route('equipment.maintenance.index'), { equipment_id: equipmentId });
</script>

<template>
    <AppLayout>
        <Head title="لوحة الصيانة" />
        <PageHeader title="لوحة الصيانة" subtitle="ما يحتاج تدخلاً الآن: المتأخر، القريب، الورشة، وقطع الغيار" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: 'لوحة الصيانة' }]">
            <template #actions>
                <Link v-if="$can('equipment.reports.view')" :href="route('equipment.reports.index', { type: 'compliance' })" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">تقرير الالتزام بالصيانة</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-xs">
            <div v-for="[k, l, c] in tiles" :key="k" class="bg-white border border-slate-200 rounded-2xl p-3"><div class="text-slate-400">{{ l }}</div><div :class="['font-black text-2xl', counts[k] ? c : 'text-slate-300']">{{ counts[k] }}</div></div>
        </div>
        <p class="mb-4 text-xs text-slate-500">هذا الشهر: {{ month.completed }} أمر صيانة مكتمل، وتكلفة قطع غيار {{ money(month.parts_cost) }}</p>

        <div class="grid xl:grid-cols-3 gap-4 text-xs">
            <section class="xl:col-span-2">
                <h3 class="font-black text-slate-700 mb-2">بنود الصيانة المستحقة</h3>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">المعدة</th><th class="px-3 py-2 text-start">البند</th><th class="px-3 py-2 text-start w-64">الحالة</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="d in due" :key="d.schedule_id" class="border-t border-slate-100">
                                <td class="px-3 py-2"><Link :href="route('equipment.show', d.equipment.id)" class="font-bold text-indigo-700">{{ d.equipment.code }}</Link> <span class="text-slate-500">{{ d.equipment.name }}</span></td>
                                <td class="px-3 py-2">{{ d.name }}<div v-if="d.product" class="text-slate-400">{{ d.product.name_ar }} × {{ Number(d.quantity) }}</div></td>
                                <td class="px-3 py-2"><MaintenanceStatus :status="d.status" /></td>
                                <td class="px-3 py-2 text-end"><button v-if="$can('equipment.maintenance.create') && d.equipment.status !== 'maintenance'" @click="openOrder(d.equipment.id)" class="px-3 py-1 rounded-lg bg-amber-500 text-white font-bold whitespace-nowrap">أمر صيانة</button><span v-else-if="d.equipment.status === 'maintenance'" class="text-indigo-600">في الورشة</span></td>
                            </tr>
                            <tr v-if="!due.length"><td colspan="4" class="px-3 py-10 text-center text-emerald-600 font-bold">لا توجد صيانة مستحقة. كل المعدات ضمن مواعيدها.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="space-y-4">
                <section>
                    <h3 class="font-black text-slate-700 mb-2">في الورشة الآن</h3>
                    <div class="bg-white border border-slate-200 rounded-2xl divide-y divide-slate-100">
                        <Link v-for="o in open" :key="o.id" :href="route('equipment.maintenance.show', o.id)" class="flex justify-between px-3 py-2 hover:bg-slate-50">
                            <span><b class="font-mono">{{ o.number }}</b> · {{ o.equipment?.code }} {{ o.equipment?.name }}</span><span class="text-slate-400">منذ {{ date(o.date) }}</span>
                        </Link>
                        <p v-if="!open.length" class="px-3 py-4 text-center text-slate-400">لا توجد أوامر مفتوحة.</p>
                    </div>
                </section>
                <section>
                    <h3 class="font-black text-slate-700 mb-2">قطع الغيار المطلوبة للبنود المستحقة</h3>
                    <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الصنف</th><th class="px-3 py-2 text-start">المطلوب</th><th class="px-3 py-2 text-start">المتوفر</th></tr></thead>
                            <tbody>
                                <tr v-for="p in parts" :key="p.product?.id" class="border-t border-slate-100">
                                    <td class="px-3 py-2">{{ p.product?.name_ar }}</td>
                                    <td class="px-3 py-2 font-mono">{{ qty(p.needed) }}</td>
                                    <td :class="['px-3 py-2 font-mono', p.short > 0 ? 'text-rose-600 font-bold' : 'text-emerald-700']">{{ qty(p.on_hand) }}<span v-if="p.short > 0"> (ينقص {{ qty(p.short) }})</span></td>
                                </tr>
                                <tr v-if="!parts.length"><td colspan="3" class="px-3 py-4 text-center text-slate-400">لا توجد أصناف مرتبطة بالبنود المستحقة.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <Link v-if="parts.some((p) => p.short > 0) && $can('purchases.requests.create')" :href="route('purchases.requests.index')" class="inline-block mt-2 text-indigo-600 font-bold">إنشاء طلب شراء ←</Link>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

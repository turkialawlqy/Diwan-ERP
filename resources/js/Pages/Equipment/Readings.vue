<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date as fmtDate } from '@/utils/format';

/**
 * Daily sheet: the supervisor fills one row per working equipment. Worked
 * hours default to the hour-meter difference; enter them for km-only
 * equipment or when the meter does not reflect billable time.
 */
const props = defineProps({ date: String, rows: Array, recent: Array });

const form = useForm({
    date: props.date,
    rows: props.rows.map((r) => ({
        equipment_id: r.equipment.id,
        hours_reading: r.reading?.hours_reading ?? '',
        km_reading: r.reading?.km_reading ?? '',
        worked_hours: r.reading ? r.reading.worked_hours : '',
        operator: r.reading?.operator ?? '',
    })),
});
const changeDate = (value) => router.get(route('equipment.readings.index'), { date: value }, { preserveState: false });
const save = () => form.post(route('equipment.readings.store'), { preserveScroll: true });
const usesHours = (e) => ['hours', 'both'].includes(e.meter_type);
const usesKm = (e) => ['km', 'both'].includes(e.meter_type);
const err = (i, f) => form.errors[`rows.${i}.${f}`];
const statusLabel = { rented: 'مؤجّرة', internal: 'في مشروع', available: 'متاحة', maintenance: 'في الصيانة' };
const where = (e) => e.active_rental ? (e.active_rental.customer?.name || e.active_rental.project?.name || '') : '';
const delta = (i, field, prevKey) => {
    const prev = props.rows[i].previous?.[prevKey];
    const v = form.rows[i][field];
    return v !== '' && v !== null && prev !== undefined && prev !== null ? Number(v) - Number(prev) : null;
};
const filled = computed(() => form.rows.filter((r) => r.hours_reading !== '' || r.km_reading !== '' || r.worked_hours !== '').length);
const today = new Date().toLocaleDateString('en-CA');
</script>

<template>
    <AppLayout>
        <Head title="قراءات العدادات اليومية" />
        <PageHeader title="قراءات العدادات اليومية" subtitle="قراءة العداد تُحدّث مواعيد الصيانة، وساعات العمل تُحسب عليها فواتير التأجير بالساعة" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: 'القراءات' }]">
            <template #actions>
                <label class="text-xs font-bold text-slate-600 flex items-center gap-2">التاريخ <input :value="date" :max="today" @change="changeDate($event.target.value)" type="date" class="rounded-xl border-slate-200 text-xs" /></label>
            </template>
        </PageHeader>

        <p v-if="form.errors.rows" class="mb-3 rounded-xl bg-rose-50 border border-rose-200 p-3 text-xs font-bold text-rose-700">{{ form.errors.rows }}</p>

        <form @submit.prevent="save" class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500">
                    <tr><th class="px-3 py-2 text-start">المعدة</th><th class="px-3 py-2 text-start">آخر قراءة</th><th class="px-3 py-2 text-start w-36">عداد الساعات</th><th class="px-3 py-2 text-start w-36">الكيلومترات</th><th class="px-3 py-2 text-start w-28">ساعات العمل</th><th class="px-3 py-2 text-start w-40">المشغّل</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="r.equipment.id" class="border-t border-slate-100 align-top">
                        <td class="px-3 py-2">
                            <Link :href="route('equipment.show', r.equipment.id)" class="font-bold text-slate-800 hover:text-indigo-700">{{ r.equipment.code }} — {{ r.equipment.name }}</Link>
                            <div class="text-slate-400">{{ statusLabel[r.equipment.status] }}<template v-if="where(r.equipment)"> · {{ where(r.equipment) }}</template></div>
                        </td>
                        <td class="px-3 py-2 text-slate-500 whitespace-nowrap">
                            <template v-if="r.previous">{{ fmtDate(r.previous.date) }}<div class="font-mono"><span v-if="r.previous.hours_reading !== null">{{ r.previous.hours_reading }} س</span> <span v-if="r.previous.km_reading !== null">{{ Number(r.previous.km_reading).toLocaleString('en-US') }} كم</span></div></template>
                            <span v-else class="font-mono">{{ usesHours(r.equipment) ? `${r.equipment.current_hours} س` : '' }} {{ usesKm(r.equipment) ? `${Number(r.equipment.current_km).toLocaleString('en-US')} كم` : '' }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="usesHours(r.equipment)">
                                <input v-model="form.rows[i].hours_reading" type="number" min="0" step="0.1" :aria-label="`عداد ساعات ${r.equipment.code}`" :class="['w-full rounded-lg text-xs font-mono', err(i, 'hours_reading') ? 'border-rose-400 bg-rose-50' : 'border-slate-200']" />
                                <div v-if="delta(i, 'hours_reading', 'hours_reading') !== null" class="text-[10px] text-slate-400">+{{ delta(i, 'hours_reading', 'hours_reading').toFixed(1) }} س</div>
                                <div v-if="err(i, 'hours_reading')" class="text-[10px] text-rose-600">{{ err(i, 'hours_reading') }}</div>
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="usesKm(r.equipment)">
                                <input v-model="form.rows[i].km_reading" type="number" min="0" step="0.1" :aria-label="`كيلومترات ${r.equipment.code}`" :class="['w-full rounded-lg text-xs font-mono', err(i, 'km_reading') ? 'border-rose-400 bg-rose-50' : 'border-slate-200']" />
                                <div v-if="delta(i, 'km_reading', 'km_reading') !== null" class="text-[10px] text-slate-400">+{{ delta(i, 'km_reading', 'km_reading').toLocaleString('en-US') }} كم</div>
                                <div v-if="err(i, 'km_reading')" class="text-[10px] text-rose-600">{{ err(i, 'km_reading') }}</div>
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <input v-model="form.rows[i].worked_hours" type="number" min="0" max="24" step="0.5" :placeholder="usesHours(r.equipment) ? 'من العداد' : ''" :aria-label="`ساعات عمل ${r.equipment.code}`" :class="['w-full rounded-lg text-xs font-mono', err(i, 'worked_hours') ? 'border-rose-400 bg-rose-50' : 'border-slate-200']" />
                            <div v-if="err(i, 'worked_hours')" class="text-[10px] text-rose-600">{{ err(i, 'worked_hours') }}</div>
                        </td>
                        <td class="px-3 py-2"><input v-model="form.rows[i].operator" :aria-label="`مشغّل ${r.equipment.code}`" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="6" class="px-3 py-10 text-center text-slate-400">لا توجد معدات بعدادات. أضف المعدات أولاً.</td></tr>
                </tbody>
            </table>
            <div v-if="rows.length && $can('equipment.readings.create')" class="flex items-center justify-between gap-3 p-3 border-t border-slate-100 bg-slate-50">
                <span class="text-slate-500">{{ filled }} من {{ rows.length }} معدة مُدخلة · الحقول الفارغة تُتجاهل</span>
                <button type="submit" :disabled="form.processing" class="px-6 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50">حفظ القراءات</button>
            </div>
        </form>
    </AppLayout>
</template>

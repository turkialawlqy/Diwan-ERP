<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ resource: Object, types: Object, days: Object, services: Array, employees: Array });
const r = props.resource;
const form = useForm({
    name: r?.name || '', type: r?.type || 'staff', employee_id: r?.employee_id || '', capacity: r?.capacity || 1, color: r?.color || '', is_active: r ? !!r.is_active : true, notes: r?.notes || '',
    hours: (r?.hours || []).map((h) => ({ weekday: h.weekday, start_time: h.start_time, end_time: h.end_time })),
    time_offs: (r?.time_offs || []).map((o) => ({ starts_at: String(o.starts_at).slice(0, 16).replace('T', ' '), ends_at: String(o.ends_at).slice(0, 16).replace('T', ' '), reason: o.reason || '' })),
    service_ids: r?.service_ids || [],
});
useUnsavedGuard(form);
const dayOrder = [6, 7, 1, 2, 3, 4, 5];
const addHours = (weekday) => form.hours.push({ weekday, start_time: '09:00', end_time: '17:00' });
const fillWeek = () => { form.hours = [6, 7, 1, 2, 3, 4].map((d) => ({ weekday: d, start_time: '09:00', end_time: '17:00' })); };
const toggleService = (id) => { form.service_ids = form.service_ids.includes(id) ? form.service_ids.filter((x) => x !== id) : [...form.service_ids, id]; };
const submit = () => (r ? form.put(route('bookings.resources.update', r.id)) : form.post(route('bookings.resources.store')));
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="r ? `تعديل ${r.name}` : 'مورد جديد'" />
        <PageHeader :title="r ? `تعديل المورد: ${r.name}` : 'مورد حجز جديد'" :crumbs="[{ label: 'الحجوزات', href: route('bookings.calendar') }, { label: 'الموارد', href: route('bookings.resources.index') }, { label: r ? r.name : 'جديد' }]" />
        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <Field label="الاسم *" :error="form.errors.name"><input v-model="form.name" required :class="input" data-resource-name /></Field>
                <Field label="النوع *" :error="form.errors.type"><select v-model="form.type" :class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="عدد الحجوزات المتزامنة" :error="form.errors.capacity" hint="1 لموظف أو غرفة؛ أكثر لقاعة تتسع لأكثر من موعد"><input v-model.number="form.capacity" type="text" inputmode="decimal" data-num :class="input" /></Field>
                <Field v-if="form.type === 'staff'" label="الموظف المرتبط"><SelectCreate v-model="form.employee_id" :options="employees" :label="(e) => e.name" placeholder="بدون" /></Field>
                <label class="flex items-center gap-2 pt-5"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" /> نشط</label>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between"><h2 class="font-bold text-slate-800">ساعات العمل الأسبوعية</h2><button type="button" class="text-indigo-600 font-bold" @click="fillWeek" data-fill-week>دوام 9–5 من السبت إلى الخميس</button></div>
                <p class="text-slate-500">أضف أكثر من فترة في اليوم نفسه لتمثيل الاستراحة. اليوم بلا فترات = إجازة. نهاية قبل البداية (مثل 22:00 — 02:00) = دوام يمتد بعد منتصف الليل ويُحسب من يوم بدايته.</p>
                <div v-for="d in dayOrder" :key="d" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                    <span class="w-20 font-bold">{{ days[d] }}</span>
                    <template v-for="(h, i) in form.hours" :key="i">
                        <span v-if="h.weekday === d" class="inline-flex items-center gap-1" :data-hours="`${d}-${i}`">
                            <input v-model="h.start_time" type="time" class="rounded-lg border-slate-200 text-xs" dir="ltr" /> — <input v-model="h.end_time" type="time" class="rounded-lg border-slate-200 text-xs" dir="ltr" />
                            <button type="button" class="p-1 text-rose-600" @click="form.hours.splice(i, 1)"><TrashIcon class="w-4 h-4" /></button>
                        </span>
                    </template>
                    <button type="button" class="px-2 py-1 rounded-lg border border-slate-200 font-bold text-slate-600" @click="addHours(d)"><PlusIcon class="w-3.5 h-3.5 inline" /> فترة</button>
                </div>
                <p v-if="form.errors.hours" class="text-rose-600 font-bold">{{ form.errors.hours }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-2">
                <h2 class="font-bold text-slate-800">إجازات وإيقاف</h2>
                <div v-for="(o, i) in form.time_offs" :key="i" class="flex flex-wrap items-center gap-2">
                    <input v-model="o.starts_at" placeholder="من (2026-06-15 09:00)" class="rounded-lg border-slate-200 text-xs w-44" dir="ltr" /> — <input v-model="o.ends_at" placeholder="إلى" class="rounded-lg border-slate-200 text-xs w-44" dir="ltr" />
                    <input v-model="o.reason" placeholder="السبب" class="rounded-lg border-slate-200 text-xs w-48" /><button type="button" class="p-1 text-rose-600" @click="form.time_offs.splice(i, 1)"><TrashIcon class="w-4 h-4" /></button>
                </div>
                <button type="button" class="px-2 py-1 rounded-lg border border-slate-200 font-bold text-slate-600" @click="form.time_offs.push({ starts_at: '', ends_at: '', reason: '' })">+ إجازة</button>
                <p v-if="form.errors.time_offs" class="text-rose-600 font-bold">{{ form.errors.time_offs }}</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <h2 class="font-bold text-slate-800 mb-2">الخدمات التي يقدّمها</h2>
                <div class="flex flex-wrap gap-2">
                    <label v-for="s in services" :key="s.id" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" :class="form.service_ids.includes(s.id) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200'">
                        <input type="checkbox" class="rounded border-slate-300" :checked="form.service_ids.includes(s.id)" @change="toggleService(s.id)" /> {{ s.name }}
                    </label>
                    <p v-if="!services.length" class="text-slate-400">لا خدمات بعد؛ أضفها من «خدمات الحجز» ثم اربطها.</p>
                </div>
            </div>
            <div class="flex gap-2"><button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-save-resource>حفظ</button><Link :href="route('bookings.resources.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">إلغاء</Link></div>
        </form>
    </AppLayout>
</template>

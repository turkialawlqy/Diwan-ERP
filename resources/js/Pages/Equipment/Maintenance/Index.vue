<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ orders: Object, filters: Object, equipment: Array, warehouses: Array, prefill: Number });
const today = new Date().toLocaleDateString('en-CA');
const blank = () => ({ equipment_id: props.prefill || '', type: 'preventive', date: today, hours_reading: '', km_reading: '', technician: '', warehouse_id: props.warehouses[0]?.id || '', notes: '' });
const form = useForm(blank());
const open = ref(false);
const create = () => { form.defaults(blank()).reset(); form.clearErrors(); open.value = true; };
const save = () => form.post(route('equipment.maintenance.store'));
const selected = computed(() => props.equipment.find((e) => e.id === Number(form.equipment_id)));

const q = ref(props.filters.q || '');
const filter = (extra = {}) => router.get(route('equipment.maintenance.index'), { ...props.filters, q: q.value || undefined, ...extra }, { preserveState: true, replace: true });
onMounted(() => { if (props.prefill) create(); });
</script>

<template>
    <AppLayout>
        <Head title="أوامر الصيانة" />
        <PageHeader title="أوامر الصيانة" subtitle="فتح الأمر، صرف قطع الغيار من المستودع، ثم إكمال الصيانة وتحديد البنود المنفذة" :crumbs="[{ label: 'المعدات', href: route('equipment.index') }, { label: 'أوامر الصيانة' }]">
            <template #actions>
                <Link :href="route('equipment.maintenance.dashboard')" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">لوحة الصيانة</Link>
                <button v-if="$can('equipment.maintenance.create')" @click="create" class="px-4 py-2 rounded-xl bg-amber-500 text-white text-xs font-bold">+ أمر صيانة</button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap gap-2 mb-3 text-xs">
            <select :value="filters.status || ''" @change="filter({ status: $event.target.value || undefined })" class="rounded-xl border-slate-200 text-xs" aria-label="الحالة"><option value="">كل الحالات</option><option value="open">مفتوح</option><option value="completed">مكتمل</option></select>
            <form @submit.prevent="filter()"><input v-model="q" type="search" placeholder="رقم الأمر أو المعدة" class="rounded-xl border-slate-200 text-xs w-56" /></form>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الأمر</th><th class="px-3 py-2 text-start">المعدة</th><th class="px-3 py-2 text-start">النوع</th><th class="px-3 py-2 text-start">التاريخ</th><th class="px-3 py-2 text-start">الفني</th><th class="px-3 py-2 text-start">أصناف مصروفة</th><th class="px-3 py-2 text-start">تكلفة القطع</th><th class="px-3 py-2 text-start">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-3 py-2"><Link :href="route('equipment.maintenance.show', o.id)" class="text-indigo-700 font-mono font-bold">{{ o.number }}</Link></td>
                        <td class="px-3 py-2">{{ o.equipment?.code }} — {{ o.equipment?.name }}</td>
                        <td class="px-3 py-2">{{ o.type === 'preventive' ? 'وقائية' : 'إصلاح عطل' }}</td>
                        <td class="px-3 py-2">{{ date(o.date) }}</td>
                        <td class="px-3 py-2">{{ o.technician || '—' }}</td>
                        <td class="px-3 py-2">{{ o.items_count }}</td>
                        <td class="px-3 py-2 font-mono">{{ money(o.parts_cost) }}</td>
                        <td class="px-3 py-2"><span :class="['px-2 py-0.5 rounded-full font-bold', o.status === 'open' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700']">{{ o.status === 'open' ? 'مفتوح' : 'مكتمل' }}</span></td>
                    </tr>
                    <tr v-if="!orders.data.length"><td colspan="8" class="px-3 py-10 text-center text-slate-400">لا توجد أوامر صيانة.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :paginator="orders" />

        <Dialog :show="open" title="أمر صيانة جديد" size="md" @close="open = false">
            <div class="grid grid-cols-2 gap-3 text-xs">
                <Field label="المعدة" :error="form.errors.equipment_id" class="col-span-2">
                    <select v-model="form.equipment_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">اختر</option><option v-for="e in equipment" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option></select>
                </Field>
                <Field label="النوع">
                    <select v-model="form.type" class="w-full rounded-xl border-slate-200 text-xs"><option value="preventive">صيانة دورية (وقائية)</option><option value="corrective">إصلاح عطل</option></select>
                </Field>
                <Field label="التاريخ" :error="form.errors.date"><input v-model="form.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="selected && ['hours','both'].includes(selected.meter_type)" label="عداد الساعات" :hint="`الحالي ${selected.current_hours}`"><input v-model="form.hours_reading" type="number" min="0" step="0.1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="selected && ['km','both'].includes(selected.meter_type)" label="الكيلومترات" :hint="`الحالي ${Number(selected.current_km).toLocaleString('en-US')}`"><input v-model="form.km_reading" type="number" min="0" step="0.1" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الفني / الورشة"><input v-model="form.technician" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مستودع الصرف"><select v-model="form.warehouse_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name_ar }}</option></select></Field>
                <Field label="وصف العمل / العطل" class="col-span-2"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
                <p v-if="form.type === 'preventive'" class="col-span-2 text-slate-500">تُضاف البنود المستحقة أو القريبة تلقائياً، ويمكنك تعديلها عند الإكمال.</p>
            </div>
            <template #footer>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">إلغاء</button>
                <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-amber-500 text-white text-xs font-bold disabled:opacity-50">فتح الأمر</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

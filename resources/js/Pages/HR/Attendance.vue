<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ date: String, employees: Array, records: Object, summary: Array });
const form = useForm({
    date: props.date,
    rows: props.employees.map((e) => ({ employee_id: e.id, name: e.name, number: e.employee_number, check_in: props.records[e.id]?.check_in?.substring(0, 5) || '', check_out: props.records[e.id]?.check_out?.substring(0, 5) || '', status: props.records[e.id]?.status || '' })),
});
const importForm = useForm({ file: null });
const changeDate = (e) => router.get(route('hr.attendance.index'), { date: e.target.value });
const save = () => form.transform((d) => ({ ...d, rows: d.rows.filter((r) => r.check_in || r.status).map(({ employee_id, check_in, check_out, status }) => ({ employee_id, check_in: check_in || null, check_out: check_out || null, status: status || null })) })).post(route('hr.attendance.store'), { preserveScroll: true });
const statusLabel = { present: 'حاضر', absent: 'غائب', leave: 'إجازة', holiday: 'عطلة رسمية', weekend: 'راحة أسبوعية' };
</script>

<template>
    <AppLayout>
        <Head title="الحضور والانصراف" />
        <PageHeader title="الحضور والانصراف" subtitle="يُحتسب التأخير والإضافي حسب وردية الموظف؛ الغياب يُخصم في المسير" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الحضور' }]">
            <template #actions><input type="date" :value="date" @change="changeDate" class="rounded-xl border-slate-200 text-xs" /></template>
        </PageHeader>
        <div class="flex flex-wrap gap-3 mb-3 text-xs">
            <span v-for="s in summary" :key="s.status" class="px-3 py-1 rounded-lg bg-white border border-slate-200">{{ statusLabel[s.status] }} (الشهر): <b>{{ s.total }}</b></span>
            <div v-if="$can('hr.attendance.create')" class="flex items-center gap-2 ms-auto">
                <input type="file" accept=".csv,.txt" @input="importForm.file = $event.target.files[0]" class="text-xs" />
                <button @click="importForm.post(route('hr.attendance.import'), { forceFormData: true })" :disabled="!importForm.file" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold disabled:opacity-40">استيراد (رقم الموظف، التاريخ، دخول، خروج)</button>
            </div>
        </div>
        <p v-for="(m, k) in importForm.errors" :key="k" class="text-xs text-rose-600 mb-2">{{ m }}</p>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الموظف</th><th class="px-3 py-2 w-28">الدخول</th><th class="px-3 py-2 w-28">الخروج</th><th class="px-3 py-2 w-36">الحالة</th><th class="px-3 py-2">تأخير / إضافي</th></tr></thead>
                <tbody>
                    <tr v-for="r in form.rows" :key="r.employee_id" class="border-t border-slate-100">
                        <td class="px-3 py-1.5">{{ r.name }} <span class="text-slate-400 font-mono">{{ r.number }}</span></td>
                        <td class="px-3 py-1.5"><input v-model="r.check_in" type="time" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                        <td class="px-3 py-1.5"><input v-model="r.check_out" type="time" class="w-full rounded-lg border-slate-200 text-xs" /></td>
                        <td class="px-3 py-1.5"><select v-model="r.status" class="w-full rounded-lg border-slate-200 text-xs"><option value="">تلقائي</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select></td>
                        <td class="px-3 py-1.5 text-center font-mono"><span class="text-rose-600">{{ records[r.employee_id]?.late_minutes || '' }}</span> / <span class="text-emerald-600">{{ records[r.employee_id]?.overtime_minutes || '' }}</span></td>
                    </tr>
                </tbody>
            </table>
            <div v-if="$can('hr.attendance.create')" class="p-3 text-end"><button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">حفظ الحضور</button></div>
        </div>
    </AppLayout>
</template>

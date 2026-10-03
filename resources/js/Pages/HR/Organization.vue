<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

defineProps({ departments: Array, positions: Array, shifts: Array, costCenters: Array });
const dept = useForm({ name: '', parent_id: '', cost_center_id: '' });
const pos = useForm({ title: '', grade: '' });
const shift = useForm({ name: '', start_time: '08:00', end_time: '17:00', break_minutes: 60, grace_minutes: 15, work_days: [7, 1, 2, 3, 4], is_default: false });
const days = [[7, 'الأحد'], [1, 'الاثنين'], [2, 'الثلاثاء'], [3, 'الأربعاء'], [4, 'الخميس'], [5, 'الجمعة'], [6, 'السبت']];
const opts = { preserveScroll: true };
const inp = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head title="الهيكل التنظيمي" />
        <PageHeader title="الهيكل التنظيمي والورديات" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الهيكل التنظيمي' }]" />
        <div class="grid lg:grid-cols-3 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">الإدارات والأقسام</h3>
                <div v-for="d in departments" :key="d.id" class="flex justify-between py-1 border-b border-slate-50"><span>{{ d.parent ? d.parent.name + ' / ' : '' }}<b>{{ d.name }}</b> <span class="text-slate-400">{{ d.cost_center?.code }}</span></span><span>{{ d.employees_count }}</span></div>
                <div v-if="$can('hr.organization.manage')" class="space-y-2 mt-3">
                    <input v-model="dept.name" placeholder="اسم الإدارة" :class="inp" />
                    <select v-model="dept.parent_id" :class="inp"><option value="">إدارة رئيسية</option><option v-for="d in departments" :key="d.id" :value="d.id">تابعة لـ {{ d.name }}</option></select>
                    <select v-model="dept.cost_center_id" :class="inp"><option value="">بدون مركز تكلفة</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} {{ c.name_ar }}</option></select>
                    <button @click="dept.post(route('hr.departments.store'), { ...opts, onSuccess: () => dept.reset() })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إضافة</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">المسميات والدرجات</h3>
                <div v-for="p in positions" :key="p.id" class="flex justify-between py-1 border-b border-slate-50"><b>{{ p.title }}</b><span class="text-slate-400">{{ p.grade }}</span></div>
                <div v-if="$can('hr.organization.manage')" class="grid grid-cols-3 gap-2 mt-3">
                    <input v-model="pos.title" placeholder="المسمى" class="col-span-2 rounded-xl border-slate-200 text-xs" />
                    <input v-model="pos.grade" placeholder="الدرجة" class="rounded-xl border-slate-200 text-xs" />
                    <button @click="pos.post(route('hr.positions.store'), { ...opts, onSuccess: () => pos.reset() })" class="col-span-3 px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إضافة</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">الورديات</h3>
                <div v-for="s in shifts" :key="s.id" class="py-1 border-b border-slate-50"><b>{{ s.name }}</b> <span class="font-mono">{{ s.start_time.substring(0, 5) }}–{{ s.end_time.substring(0, 5) }}</span> <span v-if="s.is_default" class="text-indigo-600">(افتراضية)</span></div>
                <div v-if="$can('hr.organization.manage')" class="space-y-2 mt-3">
                    <input v-model="shift.name" placeholder="اسم الوردية" :class="inp" />
                    <div class="grid grid-cols-2 gap-2"><input v-model="shift.start_time" type="time" :class="inp" /><input v-model="shift.end_time" type="time" :class="inp" /></div>
                    <div class="grid grid-cols-2 gap-2"><label>استراحة (د)<input v-model="shift.break_minutes" type="number" :class="inp" /></label><label>سماحية التأخير (د)<input v-model="shift.grace_minutes" type="number" :class="inp" /></label></div>
                    <div class="flex flex-wrap gap-2"><label v-for="[n, l] in days" :key="n" class="flex items-center gap-1"><input v-model="shift.work_days" :value="n" type="checkbox" class="rounded" /> {{ l }}</label></div>
                    <label class="flex items-center gap-1"><input v-model="shift.is_default" type="checkbox" class="rounded" /> الوردية الافتراضية</label>
                    <p v-for="(m, k) in shift.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="shift.post(route('hr.shifts.store'), { ...opts, onSuccess: () => shift.reset('name') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إضافة</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ask } from '@/composables/useDialog';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { date } from '@/utils/format';

const props = defineProps({ requests: Object, types: Array, employees: Array, status: String });
const form = useForm({ employee_id: '', leave_type_id: props.types[0]?.id || '', start_date: '', end_date: '', reason: '' });
const decide = async (r, approve) => {
    let note = null;
    if (!approve) {
        note = await ask('سبب الرفض (يظهر للموظف):', { title: 'رفض طلب الإجازة', type: 'textarea', confirmText: 'رفض', danger: true });
        if (note === null) return;
    }
    router.post(route('hr.leaves.decide', r.id), { approve, note }, { preserveScroll: true });
};
const filter = (s) => router.get(route('hr.leaves.index'), { status: s }, { preserveState: true });
const pay = { full: 'مدفوعة', sick_tiers: 'حسب شرائح المرضية', unpaid: 'بدون راتب' };
</script>

<template>
    <AppLayout>
        <Head title="الإجازات" />
        <PageHeader title="الإجازات" subtitle="وفق نظام العمل: سنوية 21/30 يوماً، مرضية بشرائح، وضع، زواج، وفاة، حج" :crumbs="[{ label: 'الموارد البشرية' }, { label: 'الإجازات' }]" />
        <div class="grid lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="flex gap-2 p-3 border-b border-slate-100 text-xs">
                    <button v-for="s in ['pending', 'approved', 'rejected', 'all']" :key="s" @click="filter(s)" :class="status === s ? 'bg-slate-800 text-white' : 'bg-slate-50'" class="px-3 py-1 rounded-lg font-bold">{{ { pending: 'قيد الانتظار', approved: 'معتمدة', rejected: 'مرفوضة', all: 'الكل' }[s] }}</button>
                </div>
                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الموظف</th><th class="px-3 py-2">النوع</th><th class="px-3 py-2">من</th><th class="px-3 py-2">إلى</th><th class="px-3 py-2">أيام</th><th class="px-3 py-2">الحالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ r.employee?.name }}</td><td class="px-3 py-2 text-center">{{ r.type?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ date(r.start_date) }}</td><td class="px-3 py-2 text-center font-mono">{{ date(r.end_date) }}</td><td class="px-3 py-2 text-center">{{ r.days }}</td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="r.status" /></td>
                            <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="r.status === 'pending' && $can('hr.leaves.approve')"><button @click="decide(r, true)" class="text-emerald-600 font-bold me-2">اعتماد</button><button @click="decide(r, false)" class="text-rose-600 font-bold">رفض</button></template></td>
                        </tr>
                        <tr v-if="!requests.data.length"><td colspan="7" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                </table></div>
                <Pagination :paginator="requests" />
            </div>
            <div class="space-y-4 text-xs">
                <div v-if="$can('hr.leaves.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                    <h3 class="font-black text-slate-700">طلب إجازة</h3>
                    <select v-model="form.employee_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الموظف...</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
                    <select v-model="form.leave_type_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                    <div class="grid grid-cols-2 gap-2"><input v-model="form.start_date" type="date" class="rounded-xl border-slate-200 text-xs" /><input v-model="form.end_date" type="date" class="rounded-xl border-slate-200 text-xs" /></div>
                    <input v-model="form.reason" placeholder="السبب" class="w-full rounded-xl border-slate-200 text-xs" />
                    <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="form.post(route('hr.leaves.store'), { preserveScroll: true, onSuccess: () => form.reset('start_date', 'end_date', 'reason') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">تسجيل</button>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <h3 class="font-black text-slate-700 mb-2">أنواع الإجازات</h3>
                    <div v-for="t in types" :key="t.id" class="flex justify-between py-1 border-b border-slate-50"><span>{{ t.name }}</span><span class="text-slate-500">{{ t.days_per_year || '—' }} يوم · {{ pay[t.pay_rule] }}</span></div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { ask } from '@/composables/useDialog';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { date } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ requests: Object, types: Array, employees: Array, status: String });
const form = useForm({ employee_id: '', leave_type_id: props.types[0]?.id || '', start_date: '', end_date: '', half_day: false, reason: '' });
const decide = async (r, approve) => {
    let note = null;
    if (!approve) {
        note = await ask(t('hr2_hr_leaves.reason_for_rejection_shown_to_th'), { title: t('hr2_hr_leaves.reject_the_leave_request'), type: 'textarea', confirmText: t('hr2_hr_leaves.reject'), danger: true });
        if (note === null) return;
    }
    router.post(route('hr.leaves.decide', r.id), { approve, note }, { preserveScroll: true });
};
const filter = (s) => router.get(route('hr.leaves.index'), { status: s }, { preserveState: true });
const pay = computed(() => ({ full: t('hr2_hr_leaves.paid'), sick_tiers: t('hr2_hr_leaves.by_sick_leave_tiers'), unpaid: t('hr2_hr_leaves.unpaid') }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('hr_leaves.leave')" />
        <PageHeader :title="$t('hr_leaves.leave')" :subtitle="$t('hr_leaves.per_the_labor_law_annual_21_30_d')" :crumbs="[{ label: $t('hr2_hr_leaves.human_resources') }, { label: $t('hr2_hr_leaves.leave') }]" />
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="flex gap-2 p-3 border-b border-slate-100 text-xs">
                    <button v-for="s in ['pending', 'approved', 'rejected', 'all']" :key="s" @click="filter(s)" :class="status === s ? 'bg-slate-800 text-white' : 'bg-slate-50'" class="px-3 py-1 rounded-lg font-bold">{{ { pending: $t('hr2_hr_leaves.pending'), approved: $t('hr2_hr_leaves.approved'), rejected: $t('hr2_hr_leaves.rejected'), all: $t('hr2_hr_leaves.all') }[s] }}</button>
                </div>
                <div class="overflow-x-auto"><table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('hr_leaves.employee') }}</th><th class="px-3 py-2">{{ $t('hr_leaves.type') }}</th><th class="px-3 py-2">{{ $t('hr_leaves.from') }}</th><th class="px-3 py-2">{{ $t('hr_leaves.to') }}</th><th class="px-3 py-2">{{ $t('hr_leaves.days') }}</th><th class="px-3 py-2">{{ $t('hr_leaves.status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id" class="border-t border-slate-100">
                            <td class="px-3 py-2">{{ r.employee?.name }}</td><td class="px-3 py-2 text-center">{{ r.type?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ date(r.start_date) }}</td><td class="px-3 py-2 text-center font-mono">{{ date(r.end_date) }}</td><td class="px-3 py-2 text-center">{{ r.days }}</td>
                            <td class="px-3 py-2 text-center"><StatusBadge :status="r.status" /></td>
                            <td class="px-3 py-2 text-end whitespace-nowrap"><template v-if="r.status === 'pending' && $can('hr.leaves.approve')"><button @click="decide(r, true)" class="text-emerald-600 font-bold me-2">{{ $t('hr_leaves.approve') }}</button><button @click="decide(r, false)" class="text-rose-600 font-bold">{{ $t('hr_leaves.reject') }}</button></template></td>
                        </tr>
                        <tr v-if="!requests.data.length"><td colspan="7" class="py-8 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                </table></div>
                <Pagination :paginator="requests" />
            </div>
            <div class="space-y-4 text-xs">
                <div v-if="$can('hr.leaves.create')" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-2">
                    <h3 class="font-black text-slate-700">{{ $t('hr_leaves.request_leave') }}</h3>
                    <SelectCreate v-model="form.employee_id" :options="employees" entity="employee" label="name" :placeholder="$t('hr_leaves.employee_2')" />
                    <select v-model="form.leave_type_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                    <!-- Side by side only where the side card leaves room for two whole dates. -->
                    <div class="grid grid-cols-2 lg:grid-cols-1 xl:grid-cols-2 gap-2"><DatePicker v-model="form.start_date" class="w-full rounded-xl border-slate-200 text-xs" /><DatePicker v-model="form.end_date" class="w-full rounded-xl border-slate-200 text-xs" /></div>
                    <label class="inline-flex items-center gap-1.5 text-xs"><input type="checkbox" v-model="form.half_day" data-half-day /> {{ $t('hr_leaves.half_day_one_day_only') }}</label>
                    <p v-if="form.errors.half_day" class="text-rose-600 font-bold">{{ form.errors.half_day }}</p>
                    <p v-if="form.errors.leave_type_id" class="text-rose-600 font-bold">{{ form.errors.leave_type_id }}</p>
                    <input v-model="form.reason" :placeholder="$t('hr_leaves.reason')" class="w-full rounded-xl border-slate-200 text-xs" />
                    <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="form.post(route('hr.leaves.store'), { preserveScroll: true, onSuccess: () => form.reset('start_date', 'end_date', 'reason') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('hr_leaves.record') }}</button>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl p-4">
                    <h3 class="font-black text-slate-700 mb-2">{{ $t('hr_leaves.leave_types') }}</h3>
                    <div v-for="t in types" :key="t.id" class="flex justify-between py-1 border-b border-slate-50"><span>{{ t.name }}</span><span class="text-slate-500">{{ $t('hr2_hr_leaves.n_days_n', { n0: t.days_per_year || '—', n1: pay[t.pay_rule] }) }}</span></div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import { PaperAirplaneIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ adminRequest: Object, lines: Array, statuses: Object, lineStatuses: Object, channels: Object, flow: Object, handlers: Array, canView: Boolean });
const r = computed(() => props.adminRequest);
const tone = { new: 'bg-slate-100 text-slate-600', received: 'bg-sky-100 text-sky-700', assigned: 'bg-indigo-100 text-indigo-700', notified: 'bg-amber-100 text-amber-700', unreachable: 'bg-rose-100 text-rose-700', attended: 'bg-emerald-100 text-emerald-700', absent: 'bg-rose-100 text-rose-700' };
const when = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
const post = (name, line, data = {}) => router.post(route(`hr.admin-requests.lines.${name}`, [r.value.id, line.id]), data, { preserveScroll: true, onSuccess: () => { dlg.value = null; } });
const can = (line, to) => (props.flow[line.status] || []).includes(to);

// One dialog for the actions that need input: assign / notify / attendance.
const dlg = ref(null);
const f = reactive({ line: null, assignee_user_id: '', channel: 'call', reached: true, attended: true, note: '' });
const open = (kind, line) => { Object.assign(f, { line, assignee_user_id: '', channel: 'call', reached: true, attended: true, note: '' }); dlg.value = kind; };
const confirm = () => {
    if (dlg.value === 'assign') post('assign', f.line, { assignee_user_id: f.assignee_user_id, note: f.note });
    if (dlg.value === 'notify') post('notify', f.line, { reached: f.reached, notify_channel: f.reached ? f.channel : null, note: f.note });
    if (dlg.value === 'attendance') post('attendance', f.line, { attended: f.attended, note: f.note });
};
const send = () => router.post(route('hr.admin-requests.send', r.value.id), {}, { preserveScroll: true });
const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="`طلب إداري ${r.number}`" />
        <PageHeader :title="`${r.number} — ${r.title}`" :crumbs="[{ label: 'الطلبات الإدارية', href: canView ? route('hr.admin-requests.index') : route('hr.admin-requests.inbox') }, { label: r.number }]">
            <template #actions>
                <button v-if="r.status === 'draft' && $can('hr.admin_requests.create')" @click="send" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-send><PaperAirplaneIcon class="w-4 h-4" /> إرسال الطلب</button>
                <Link v-if="r.status === 'draft' && $can('hr.admin_requests.edit')" :href="route('hr.admin-requests.edit', r.id)" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">تعديل</Link>
            </template>
        </PageHeader>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
            <div><div class="text-slate-400">الحالة</div><div class="font-bold" data-request-status>{{ statuses[r.status] }}</div></div>
            <div><div class="text-slate-400">موعد الحضور</div><div class="font-mono font-bold">{{ when(r.attend_at) }}</div></div>
            <div><div class="text-slate-400">المكان</div><div class="font-bold">{{ r.location || '—' }}</div></div>
            <div><div class="text-slate-400">مقدّم الطلب</div><div class="font-bold">{{ r.requester?.name || '—' }}</div></div>
            <div v-if="r.body" class="col-span-2 md:col-span-4 whitespace-pre-line text-slate-600">{{ r.body }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-2 text-start">الموظف</th><th class="px-4 py-2 text-start">الإدارة</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 text-start">المعالج</th><th class="px-4 py-2 text-start">الإجراء</th></tr></thead>
                <tbody>
                    <template v-for="l in lines" :key="l.id">
                        <tr class="border-t border-slate-100" :data-line="l.employee.name">
                            <td class="px-4 py-2 font-bold">{{ l.employee.name }}<span class="block font-mono font-normal text-slate-400">{{ l.employee.employee_number }}</span></td>
                            <td class="px-4 py-2">{{ l.department?.name || '—' }}</td>
                            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-bold" :class="tone[l.status]" data-line-status>{{ lineStatuses[l.status] }}</span>
                                <span v-if="l.notify_channel" class="block mt-1 text-slate-500">{{ channels[l.notify_channel] }}</span></td>
                            <td class="px-4 py-2">{{ l.assignee?.name || l.handler?.name || '—' }}</td>
                            <td class="px-4 py-2">
                                <div v-if="r.status !== 'draft'" class="flex flex-wrap gap-1.5">
                                    <template v-if="l.can_manage">
                                        <button v-if="can(l, 'received') && l.status === 'new'" class="px-2.5 py-1 rounded-lg bg-sky-600 text-white font-bold" data-act="receive" @click="post('receive', l)">استلام</button>
                                        <button v-if="l.status === 'unreachable'" class="px-2.5 py-1 rounded-lg bg-slate-600 text-white font-bold" data-act="reopen" @click="post('reopen', l)">إعادة المحاولة</button>
                                        <button v-if="can(l, 'assigned') && l.status !== 'unreachable'" class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-bold" data-act="assign" @click="open('assign', l)">إسناد لمختص</button>
                                    </template>
                                    <button v-if="l.can_handle && (can(l, 'notified'))" class="px-2.5 py-1 rounded-lg bg-amber-600 text-white font-bold" data-act="notify" @click="open('notify', l)">تسجيل التبليغ</button>
                                    <button v-if="l.can_handle && can(l, 'attended')" class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-bold" data-act="attendance" @click="open('attendance', l)">تسجيل الحضور</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="l.events.length" class="bg-slate-50/60"><td colspan="5" class="px-4 py-1.5 text-slate-500">
                            <span v-for="e in l.events" :key="e.id" class="inline-block me-4">{{ lineStatuses[e.to_status] }} — {{ e.user?.name }} <span class="font-mono">{{ when(e.created_at) }}</span><template v-if="e.note"> ({{ e.note }})</template></span>
                        </td></tr>
                    </template>
                </tbody>
            </table>
        </div>

        <Dialog :show="!!dlg" :title="dlg === 'assign' ? 'إسناد لمختص' : dlg === 'notify' ? 'نتيجة التبليغ' : 'تسجيل الحضور'" @close="dlg = null">
            <div class="space-y-3 text-xs">
                <label v-if="dlg === 'assign'" class="block">المختص<select v-model="f.assignee_user_id" :class="input" data-assignee><option value="">اختر...</option><option v-for="h in handlers" :key="h.id" :value="h.id">{{ h.name }}</option></select></label>
                <template v-if="dlg === 'notify'">
                    <div class="flex gap-4"><label><input type="radio" v-model="f.reached" :value="true" /> تم التبليغ</label><label><input type="radio" v-model="f.reached" :value="false" /> تعذر التبليغ</label></div>
                    <label v-if="f.reached" class="block">الوسيلة<select v-model="f.channel" :class="input" data-channel><option v-for="(l, k) in channels" :key="k" :value="k">{{ l }}</option></select></label>
                </template>
                <div v-if="dlg === 'attendance'" class="flex gap-4"><label><input type="radio" v-model="f.attended" :value="true" /> تم حضوره</label><label><input type="radio" v-model="f.attended" :value="false" /> لم يحضر</label></div>
                <label class="block">ملاحظة<textarea v-model="f.note" rows="2" :class="input" data-note></textarea></label>
                <div class="flex justify-end gap-2"><button class="px-4 py-2 rounded-xl border border-slate-200" @click="dlg = null">إلغاء</button><button class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-confirm @click="confirm">تأكيد</button></div>
            </div>
        </Dialog>
    </AppLayout>
</template>

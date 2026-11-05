<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { money } from '@/utils/format';

const props = defineProps({ rules: Array, types: Object, recent: Array, requests: { type: Array, default: () => [] } });
const decide = (r, decision) => router.post(route('approval-requests.decide', { approvalRequest: r.id, decision }), {}, { preserveScroll: true });
const form = useForm({ document_type: Object.keys(props.types)[0], min_amount: 0, is_active: true });
const save = () => form.post(route('approval-rules.store'), { onSuccess: () => form.reset('min_amount') });
const destroy = (rule) => { if (confirm('حذف القاعدة؟')) router.delete(route('approval-rules.destroy', rule.id)); };
const actions = { submitted: 'أُرسل', approved: 'اعتُمد', rejected: 'رُفض' };
</script>

<template>
    <AppLayout>
        <Head title="قواعد الاعتماد" />
        <PageHeader title="قواعد الاعتماد" subtitle="المستندات التي تبلغ الحد تنتظر اعتماد مستخدم يملك صلاحية الاعتماد، ولا يعتمد المنشئ مستنده" :crumbs="[{ label: 'الإعدادات' }, { label: 'قواعد الاعتماد' }]" />
        <div v-if="requests.length" class="bg-white border border-amber-200 rounded-2xl p-4 mb-4 text-xs" data-approval-requests>
            <h3 class="font-black text-slate-700 mb-2">طلبات بانتظار القرار ({{ requests.length }})</h3>
            <div v-for="r in requests" :key="r.id" class="flex items-center gap-3 py-1.5 border-t border-slate-100" data-approval-request>
                <span class="px-2 py-0.5 rounded-lg bg-slate-100 font-bold">{{ types[r.document_type] || r.document_type }}</span>
                <span class="flex-1">{{ r.description }}</span>
                <span class="font-mono">{{ money(r.amount) }}</span>
                <span class="text-slate-400">{{ r.requester?.name }}</span>
                <template v-if="r.can_decide">
                    <button @click="decide(r, 'approve')" data-approve class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-bold">اعتماد</button>
                    <button @click="decide(r, 'reject')" class="px-2.5 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold">رفض</button>
                </template>
            </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">نوع المستند</th><th class="px-4 py-2 text-end">يتطلب اعتماداً من مبلغ</th><th class="px-4 py-2">الحالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in rules" :key="r.id" class="border-t border-slate-100">
                            <td class="px-4 py-2">{{ types[r.document_type] }}</td>
                            <td class="px-4 py-2 text-end font-mono">{{ money(r.min_amount) }}</td>
                            <td class="px-4 py-2 text-center">{{ r.is_active ? 'فعالة' : 'موقوفة' }}</td>
                            <td class="px-4 py-2 text-end"><button v-if="$can('admin.approvals.manage')" @click="destroy(r)" class="text-rose-600 font-bold">حذف</button></td>
                        </tr>
                        <tr v-if="!rules.length"><td colspan="4" class="py-8 text-center text-slate-400">لا توجد قواعد: كل المستندات تُعتمد تلقائياً.</td></tr>
                    </tbody>
                </table>
                <div v-if="$can('admin.approvals.manage')" class="grid grid-cols-4 gap-3 p-4 border-t border-slate-100 items-end">
                    <Field label="نوع المستند"><select v-model="form.document_type" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option></select></Field>
                    <Field label="من مبلغ" :error="form.errors.min_amount"><input v-model.number="form.min_amount" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <label class="text-xs flex items-center gap-2 pb-2"><input v-model="form.is_active" type="checkbox" class="rounded" /> فعالة</label>
                    <button @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إضافة</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                <h3 class="font-black text-slate-700 mb-2">آخر عمليات الاعتماد</h3>
                <div v-for="a in recent" :key="a.id" class="py-1 border-b border-slate-50">
                    <b>{{ actions[a.action] || a.action }}</b> — {{ a.user?.name || 'النظام' }}
                    <span class="text-slate-400">{{ a.approvable_type.split('\\').pop() }} #{{ a.approvable_id }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

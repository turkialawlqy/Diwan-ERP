<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';

const props = defineProps({ ticket: Object, subscription: Object, overdue: Boolean, agents: Array, categories: Array, surveyLink: String, statuses: Object, priorities: Object, channels: Object });
const t = computed(() => props.ticket);
const closed = computed(() => t.value.status === 'closed');
const patch = (data) => router.patch(route('support.tickets.update', t.value.id), data, { preserveScroll: true });
const reply = useForm({ body: '', type: 'public' });
const send = () => reply.post(route('support.tickets.reply', t.value.id), { preserveScroll: true, onSuccess: () => reply.reset('body') });
const fmt = (d) => (d ? d.slice(0, 16).replace('T', ' ') : '—');
const copy = () => navigator.clipboard?.writeText(props.surveyLink);
const stat = { open: 'bg-emerald-50 text-emerald-700 border-emerald-200', pending: 'bg-sky-50 text-sky-700 border-sky-200', resolved: 'bg-violet-50 text-violet-700 border-violet-200', closed: 'bg-slate-100 text-slate-500 border-slate-200' };
const bubble = { public: 'bg-indigo-50 border-indigo-100', internal: 'bg-amber-50 border-amber-200', customer: 'bg-white border-slate-200' };
const label = { public: 'رد للعميل', internal: 'ملاحظة داخلية', customer: 'من العميل' };
const sel = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="`تذكرة ${t.number}`" />
        <PageHeader :title="`${t.number} — ${t.subject}`" :subtitle="t.customer?.name || t.contact_name" :crumbs="[{ label: 'خدمة العملاء' }, { label: 'التذاكر', href: route('support.tickets.index') }, { label: t.number }]">
            <template #actions>
                <template v-if="$can('support.tickets.edit')">
                    <button v-if="t.status === 'open' || t.status === 'pending'" type="button" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold" @click="patch({ status: 'resolved' })" data-resolve>تم الحل</button>
                    <button v-if="t.status === 'resolved'" type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold" @click="patch({ status: 'closed' })" data-close>إغلاق</button>
                    <button v-if="t.status === 'resolved' || closed" type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold" @click="patch({ status: 'open' })" data-reopen>إعادة فتح</button>
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 space-y-3">
                <div v-if="t.description" class="bg-white border border-slate-200 rounded-2xl p-4 whitespace-pre-line leading-relaxed">{{ t.description }}</div>

                <div v-for="m in t.messages" :key="m.id" class="border rounded-2xl p-3" :class="bubble[m.type]" :data-message="m.type">
                    <div class="flex justify-between text-3xs text-slate-500 mb-1"><b>{{ m.type === 'customer' ? (t.customer?.name || t.contact_name || 'العميل') : m.user?.name }} · {{ label[m.type] }}</b><span class="font-mono">{{ fmt(m.created_at) }}</span></div>
                    <p class="whitespace-pre-line leading-relaxed">{{ m.body }}</p>
                </div>

                <form v-if="!closed && $can('support.tickets.edit')" @submit.prevent="send" class="bg-white border border-slate-200 rounded-2xl p-3 space-y-2">
                    <div class="flex gap-1 text-xs font-bold">
                        <label v-for="(l, k) in label" :key="k" class="px-3 py-1 rounded-lg border cursor-pointer" :class="reply.type === k ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-600'">
                            <input type="radio" v-model="reply.type" :value="k" class="sr-only" :data-reply-type="k" />{{ l }}
                        </label>
                    </div>
                    <textarea v-model="reply.body" rows="4" required class="w-full rounded-xl border-slate-200 text-xs" placeholder="اكتب الرد..." data-reply-body></textarea>
                    <p v-if="reply.errors.body" class="text-rose-600">{{ reply.errors.body }}</p>
                    <button type="submit" :disabled="reply.processing || !reply.body" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold disabled:opacity-50" data-send-reply>إرسال</button>
                </form>
                <p v-if="closed" class="text-center text-slate-400">التذكرة مغلقة. أعد فتحها للرد.</p>
            </div>

            <div class="space-y-3">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-3">
                    <div class="flex items-center justify-between"><span class="text-slate-500">الحالة</span><span class="px-2 py-0.5 rounded-full border font-bold" :class="stat[t.status]" data-status>{{ statuses[t.status] }}</span></div>
                    <template v-if="$can('support.tickets.edit')">
                        <label class="block"><span class="text-slate-500">الأولوية</span><select :value="t.priority" @change="patch({ priority: $event.target.value })" :class="sel" data-priority><option v-for="(l, k) in priorities" :key="k" :value="k">{{ l }}</option></select></label>
                        <label class="block"><span class="text-slate-500">المسؤول</span><select :value="t.assigned_to || ''" @change="$event.target.value ? patch({ assigned_to: Number($event.target.value) }) : patch({ unassign: true })" :class="sel" data-assignee><option value="">غير محدد</option><option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                        <div class="block"><span class="text-slate-500">التصنيف</span><SelectCreate :model-value="t.category_id || ''" @update:model-value="(v) => patch({ category_id: v || null })" :options="categories" entity="support_category" :label="(c) => c.name" placeholder="بدون" /></div>
                    </template>
                    <template v-else>
                        <p>الأولوية: <b>{{ priorities[t.priority] }}</b></p><p>المسؤول: <b>{{ t.assignee?.name || '—' }}</b></p>
                    </template>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5" data-sla>
                    <h3 class="font-bold text-slate-800">مهل الخدمة (SLA)</h3>
                    <p class="flex justify-between"><span class="text-slate-500">الرد الأول</span><span class="font-mono" :class="t.response_breached ? 'text-rose-600 font-bold' : ''">{{ t.first_response_at ? fmt(t.first_response_at) : `قبل ${fmt(t.first_response_due_at)}` }}</span></p>
                    <p class="flex justify-between"><span class="text-slate-500">الحل</span><span class="font-mono" :class="t.resolution_breached || (overdue && !t.resolved_at) ? 'text-rose-600 font-bold' : ''">{{ t.resolved_at ? fmt(t.resolved_at) : `قبل ${fmt(t.resolution_due_at)}` }}</span></p>
                    <p v-if="overdue" class="text-rose-600 font-bold">متأخرة عن المهلة</p>
                    <p v-if="t.response_breached || t.resolution_breached" class="text-rose-600">تجاوزت المهلة</p>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5">
                    <h3 class="font-bold text-slate-800">التفاصيل</h3>
                    <p><span class="text-slate-500">العميل: </span><Link v-if="t.customer" :href="route('sales.clients.show', t.customer.id)" class="text-indigo-600 font-bold">{{ t.customer.name }}</Link><span v-else>{{ t.contact_name || '—' }}</span></p>
                    <p v-if="t.contact_phone || t.customer?.phone" class="font-mono" dir="ltr">{{ t.contact_phone || t.customer?.phone }}</p>
                    <p v-if="t.contact_email || t.customer?.email" dir="ltr">{{ t.contact_email || t.customer?.email }}</p>
                    <p><span class="text-slate-500">القناة: </span>{{ channels[t.channel] }}</p>
                    <p v-if="t.category"><span class="text-slate-500">التصنيف: </span>{{ t.category.name }}</p>
                    <p v-if="subscription"><span class="text-slate-500">الاشتراك: </span><Link :href="route('sales.subscriptions.show', subscription.id)" class="text-indigo-600 font-bold font-mono">{{ subscription.number }}</Link></p>
                    <p><span class="text-slate-500">فُتحت: </span><span class="font-mono">{{ fmt(t.created_at) }}</span></p>
                </div>

                <div v-if="surveyLink || t.rating" class="bg-white border border-slate-200 rounded-2xl p-4 space-y-1.5" data-survey>
                    <h3 class="font-bold text-slate-800">رضا العميل</h3>
                    <p v-if="t.rating" class="text-amber-500 text-base">{{ '★'.repeat(t.rating) }}<span class="text-slate-300">{{ '★'.repeat(5 - t.rating) }}</span></p>
                    <p v-if="t.rating_comment" class="text-slate-600">{{ t.rating_comment }}</p>
                    <button v-if="surveyLink" type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold" @click="copy" data-copy-survey>نسخ رابط التقييم</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

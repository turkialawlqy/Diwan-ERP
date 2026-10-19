<script setup>
import { confirmAction } from '@/composables/useDialog';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { date } from '@/utils/format';

const props = defineProps({ tokens: Array, abilities: Array, webhooks: Array, events: Object, newToken: String, newSecret: String, apiBase: String });
const tokenForm = useForm({ name: '', abilities: [...props.abilities], expires_in_days: 365 });
const hookForm = useForm({ url: '', events: [] });
const opts = { preserveScroll: true };
const remove = async (url, message) => { if (await confirmAction(message, { danger: true, confirmText: 'تأكيد' })) router.delete(url, opts); };
</script>

<template>
    <AppLayout>
        <Head title="الربط البرمجي" />
        <PageHeader title="الربط البرمجي" subtitle="رموز API لأنظمتك الأخرى، وإشعارات Webhooks عند الأحداث" :crumbs="[{ label: 'الإعدادات' }, { label: 'الربط البرمجي' }]" />
        <div v-if="newToken" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs"><b>الرمز الجديد (لن يظهر مرة أخرى):</b><p class="font-mono break-all mt-1" dir="ltr">{{ newToken }}</p></div>
        <div v-if="newSecret" class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs"><b>مفتاح توقيع الويب هوك (لن يظهر مرة أخرى):</b><p class="font-mono break-all mt-1" dir="ltr">{{ newSecret }}</p></div>
        <div class="grid lg:grid-cols-2 gap-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700">رموز الوصول (API)</h3>
                <p class="text-slate-500 mb-3" dir="ltr">Base URL: <span class="font-mono">{{ apiBase }}</span> — Header: <span class="font-mono">Authorization: Bearer &lt;token&gt;</span></p>
                <div v-for="t in tokens" :key="t.id" class="flex justify-between py-1.5 border-b border-slate-50">
                    <div><b>{{ t.name }}</b><div class="text-slate-400">{{ t.abilities.join('، ') }}</div><div class="text-slate-400">آخر استخدام: {{ t.last_used_at ? date(t.last_used_at) : '—' }} · ينتهي: {{ t.expires_at ? date(t.expires_at) : 'بدون' }}</div></div>
                    <button @click="remove(route('integrations.tokens.destroy', t.id), 'إلغاء هذا الرمز؟ ستتوقف الأنظمة التي تستخدمه فوراً.')" class="text-rose-600 font-bold h-fit">إلغاء</button>
                </div>
                <div class="space-y-2 mt-3">
                    <input v-model="tokenForm.name" placeholder="اسم الرمز (مثال: متجر إلكتروني)" class="w-full rounded-xl border-slate-200 text-xs" />
                    <div class="flex flex-wrap gap-2"><label v-for="a in abilities" :key="a" class="flex items-center gap-1 font-mono"><input v-model="tokenForm.abilities" :value="a" type="checkbox" class="rounded" /> {{ a }}</label></div>
                    <label class="block">الصلاحية (يوم)<input v-model="tokenForm.expires_in_days" type="number" class="w-full rounded-xl border-slate-200 text-xs" /></label>
                    <p v-for="(m, k) in tokenForm.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="tokenForm.post(route('integrations.tokens.store'), { ...opts, onSuccess: () => tokenForm.reset('name') })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إنشاء رمز</button>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700">Webhooks</h3>
                <p class="text-slate-500 mb-3">كل طلب موقّع بـ HMAC-SHA256 في الترويسة X-Diwan-Signature على «timestamp.body».</p>
                <div v-for="w in webhooks" :key="w.id" class="py-1.5 border-b border-slate-50">
                    <div class="flex justify-between"><span class="font-mono break-all" dir="ltr">{{ w.url }}</span><span class="whitespace-nowrap"><button @click="router.post(route('webhooks.test', w.id), {}, opts)" class="text-indigo-600 font-bold me-2">اختبار</button><button @click="remove(route('webhooks.destroy', w.id), 'حذف هذا الـ Webhook؟')" class="text-rose-600 font-bold">حذف</button></span></div>
                    <div class="text-slate-400">{{ w.events.join('، ') }} · آخر حالة: {{ w.last_status || '—' }} {{ w.is_active ? '' : '(موقوف)' }}</div>
                </div>
                <div class="space-y-2 mt-3">
                    <input v-model="hookForm.url" placeholder="https://..." class="w-full rounded-xl border-slate-200 text-xs font-mono" dir="ltr" />
                    <div class="flex flex-wrap gap-2"><label v-for="(l, k) in events" :key="k" class="flex items-center gap-1"><input v-model="hookForm.events" :value="k" type="checkbox" class="rounded" /> {{ l }}</label></div>
                    <p v-for="(m, k) in hookForm.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="hookForm.post(route('webhooks.store'), { ...opts, onSuccess: () => hookForm.reset() })" class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white font-bold">إضافة</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

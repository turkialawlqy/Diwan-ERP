<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ company: Object, plan: Object, state: String, usage: Object, plans: Array, modules: Object, invoices: Array, exports: Array, centralDomain: String, payment: { type: Object, default: () => ({}) } });
const cycle = ref(props.company.billing_cycle || 'monthly');
const choose = (p) => { if (confirm(`طلب الاشتراك في باقة ${p.name_ar}؟`)) router.post(route('billing.plan'), { plan_id: p.id, billing_cycle: cycle.value }, { preserveScroll: true }); };
const domainForm = useForm({ custom_domain: props.company.custom_domain || '' });
const stateLabel = { trial: ['فترة تجريبية', 'bg-sky-50 text-sky-700'], active: ['اشتراك فعال', 'bg-emerald-50 text-emerald-700'], grace: ['فترة سماح', 'bg-amber-50 text-amber-700'], expired: ['منتهي — للعرض فقط', 'bg-rose-50 text-rose-700'] };
const pct = (u) => (u.max && u.max > 0 ? Math.min(100, Math.round((u.used / u.max) * 100)) : 0);
</script>

<template>
    <AppLayout>
        <Head title="الاشتراك" />
        <PageHeader title="الاشتراك والفوترة" :subtitle="`${company.subdomain}.${centralDomain}`" :crumbs="[{ label: 'الإعدادات' }, { label: 'الاشتراك' }]">
            <template #actions><span :class="stateLabel[state][1]" class="px-3 py-1.5 rounded-xl text-xs font-bold">{{ stateLabel[state][0] }}</span></template>
        </PageHeader>

        <div class="grid lg:grid-cols-3 gap-4 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">الباقة الحالية</h3>
                <p class="text-base font-black text-indigo-700">{{ plan?.name_ar || 'بدون باقة' }}</p>
                <p class="text-slate-500 mt-1">{{ state === 'trial' ? 'تنتهي التجربة' : 'ينتهي الاشتراك' }}: <b class="font-mono">{{ date(state === 'trial' ? company.trial_ends_at : company.subscription_ends_at) }}</b></p>
                <div class="mt-3 space-y-2">
                    <div v-for="(u, key) in usage" :key="key">
                        <div class="flex justify-between"><span>{{ u.label }}</span><span class="font-mono">{{ u.used }} / {{ u.max === null || u.max < 0 ? '∞' : u.max }}</span></div>
                        <div class="h-1.5 bg-slate-100 rounded-full"><div class="h-1.5 rounded-full" :class="pct(u) >= 90 ? 'bg-rose-500' : 'bg-indigo-500'" :style="{ width: pct(u) + '%' }"></div></div>
                    </div>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">نطاق خاص (Custom Domain)</h3>
                <div class="flex gap-2"><input v-model="domainForm.custom_domain" placeholder="erp.example.com" class="flex-1 rounded-xl border-slate-200 text-xs font-mono" dir="ltr" /><button v-if="$can('admin.billing.manage')" @click="domainForm.put(route('billing.domain'), { preserveScroll: true })" class="px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">حفظ</button></div>
                <p v-if="domainForm.errors.custom_domain" class="text-rose-600 mt-1">{{ domainForm.errors.custom_domain }}</p>
                <template v-if="company.custom_domain">
                    <p v-if="company.custom_domain_verified_at" class="mt-3 text-emerald-700 font-bold">✓ النطاق موثق ومفعل</p>
                    <div v-else class="mt-3 space-y-1">
                        <p>أضف السجلات التالية لدى مزود النطاق ثم اضغط تحقق:</p>
                        <p class="font-mono bg-slate-50 p-2 rounded-lg break-all" dir="ltr">TXT _diwan-verify.{{ company.custom_domain }} = {{ company.custom_domain_token }}</p>
                        <p class="font-mono bg-slate-50 p-2 rounded-lg" dir="ltr">CNAME {{ company.custom_domain }} → {{ company.subdomain }}.{{ centralDomain }}</p>
                        <button v-if="$can('admin.billing.manage')" @click="router.post(route('billing.domain.verify'), {}, { preserveScroll: true })" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">تحقق</button>
                    </div>
                </template>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">نسخة كاملة من بيانات المنشأة</h3>
                <p class="text-slate-500 mb-2">ملف مضغوط يضم كل جداول المنشأة بصيغة CSV (بدون كلمات المرور والمفاتيح).</p>
                <button v-if="$can('admin.data.export')" @click="router.post(route('billing.export'), {}, { preserveScroll: true })" class="px-3 py-2 rounded-xl border border-slate-200 font-bold">طلب نسخة</button>
                <div v-for="e in exports" :key="e.id" class="flex justify-between mt-2"><span class="font-mono">{{ String(e.created_at).substring(0, 16).replace('T', ' ') }}</span><a v-if="e.status === 'ready'" :href="route('billing.export.download', e.id)" class="text-indigo-600 font-bold">تنزيل</a><span v-else>{{ e.status === 'failed' ? 'فشل' : 'قيد التجهيز' }}</span></div>
            </div>
        </div>

        <div class="flex items-center gap-2 mb-3 text-xs">
            <button @click="cycle = 'monthly'" :class="cycle === 'monthly' ? 'bg-slate-800 text-white' : 'bg-white border'" class="px-3 py-1.5 rounded-xl font-bold">شهري</button>
            <button @click="cycle = 'yearly'" :class="cycle === 'yearly' ? 'bg-slate-800 text-white' : 'bg-white border'" class="px-3 py-1.5 rounded-xl font-bold">سنوي</button>
        </div>
        <div class="grid md:grid-cols-3 gap-4 mb-4 text-xs">
            <div v-for="p in plans" :key="p.id" class="bg-white border rounded-2xl p-4" :class="p.id === plan?.id ? 'border-indigo-500 ring-2 ring-indigo-100' : 'border-slate-200'">
                <div class="flex justify-between"><h3 class="font-black text-sm">{{ p.name_ar }}</h3><span v-if="p.is_popular" class="text-indigo-600 font-bold">الأكثر طلباً</span></div>
                <p class="text-lg font-black mt-1 font-mono">{{ money(cycle === 'yearly' ? p.price_yearly : p.price_monthly) }} <span class="text-xs font-normal text-slate-500">ر.س / {{ cycle === 'yearly' ? 'سنة' : 'شهر' }} + ض.ق.م</span></p>
                <p class="text-slate-500 mt-1">{{ p.description_ar }}</p>
                <ul class="mt-2 space-y-0.5">
                    <li>المستخدمون: {{ p.max_users < 0 ? 'غير محدود' : p.max_users }}</li><li>الفروع: {{ p.max_branches < 0 ? 'غير محدود' : p.max_branches }}</li>
                    <li>الموظفون: {{ p.max_employees < 0 ? 'غير محدود' : p.max_employees }}</li><li>الفواتير شهرياً: {{ p.max_invoices_per_month < 0 ? 'غير محدود' : p.max_invoices_per_month }}</li>
                    <li class="text-slate-500">{{ p.modules ? p.modules.map((m) => modules[m]).join('، ') : 'كل الوحدات' }}</li>
                </ul>
                <button v-if="$can('admin.billing.manage')" @click="choose(p)" class="w-full mt-3 px-3 py-2 rounded-xl font-bold" :class="p.id === plan?.id ? 'border border-indigo-200 text-indigo-700' : 'bg-indigo-600 text-white'">{{ p.id === plan?.id ? 'تجديد' : 'اختيار' }}</button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">الفاتورة</th><th class="px-3 py-2">الباقة</th><th class="px-3 py-2">الفترة</th><th class="px-3 py-2 text-end">الإجمالي</th><th class="px-3 py-2">الحالة</th></tr></thead>
                <tbody>
                    <tr v-for="i in invoices" :key="i.id" class="border-t border-slate-100"><td class="px-3 py-2 font-mono"><a :href="route('billing.invoices.show', i.id)" target="_blank" class="text-indigo-600 hover:underline">{{ i.number }}</a></td><td class="px-3 py-2 text-center">{{ i.plan?.name_ar }}</td><td class="px-3 py-2 text-center font-mono">{{ date(i.period_start) }} → {{ date(i.period_end) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(i.total) }}</td><td class="px-3 py-2 text-center"><StatusBadge :status="i.status" /></td></tr>
                    <tr v-if="!invoices.length"><td colspan="5" class="py-6 text-center text-slate-400">لا توجد فواتير اشتراك</td></tr>
                </tbody>
            </table>
            <div class="p-3 text-slate-600 space-y-1">
                <p>السداد بالتحويل البنكي مع ذكر رقم الفاتورة في بيان التحويل. يُفعّل الاشتراك فور تأكيد الإدارة للسداد.</p>
                <p v-if="payment?.iban" class="font-bold">
                    {{ payment.bank_name }} — {{ payment.account_name || payment.name }} — IBAN: <span class="font-mono" dir="ltr">{{ payment.iban }}</span>
                </p>
                <p v-if="payment?.email">لإرسال إشعار التحويل: <a :href="`mailto:${payment.email}`" class="text-indigo-600">{{ payment.email }}</a></p>
            </div>
        </div>
    </AppLayout>
</template>

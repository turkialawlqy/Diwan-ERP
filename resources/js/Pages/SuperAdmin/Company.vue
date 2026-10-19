<script setup>
import { ask } from '@/composables/useDialog';
import { Head, router, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { money, date } from '@/utils/format';

const props = defineProps({ company: Object, state: String, usage: Object, users: Array, invoices: Array, audit: Array, plans: Array });
const c = props.company;
const form = useForm({ subscription_plan_id: c.subscription_plan_id || '', status: c.status, trial_ends_at: c.trial_ends_at?.substring(0, 10) || '', subscription_ends_at: c.subscription_ends_at?.substring(0, 10) || '', suspended_reason: c.suspended_reason || '' });
const impersonate = async () => {
    const reason = await ask('سبب الدخول على بيانات المنشأة (يُسجّل في سجل التدقيق):', { title: 'الدخول كمدير المنشأة', type: 'textarea', required: true, minLength: 5 });
    if (reason) router.post(route('super-admin.impersonate.enter', c.id), { reason });
};
const inp = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <SuperAdminLayout>
        <Head :title="company.name" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 space-y-4 text-xs">
            <div class="flex justify-between items-center">
                <div><h1 class="text-lg font-black">{{ company.name }}</h1><p class="text-slate-500 font-mono">{{ company.subdomain }} · {{ company.tax_number }} · الحالة: {{ state }}</p></div>
                <button @click="impersonate" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">دخول للمساندة</button>
            </div>
            <div class="grid lg:grid-cols-3 gap-4">
                <div class="bg-white rounded-2xl border p-4 space-y-2">
                    <h3 class="font-black">الاشتراك</h3>
                    <label class="block">الباقة<select v-model="form.subscription_plan_id" :class="inp"><option value="">—</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name_ar }}</option></select></label>
                    <label class="block">الحالة<select v-model="form.status" :class="inp"><option value="trial">تجريبي</option><option value="active">فعال</option><option value="suspended">موقوف</option></select></label>
                    <label class="block">نهاية التجربة<input v-model="form.trial_ends_at" type="date" :class="inp" /></label>
                    <label class="block">نهاية الاشتراك<input v-model="form.subscription_ends_at" type="date" :class="inp" /></label>
                    <label v-if="form.status === 'suspended'" class="block">سبب الإيقاف<input v-model="form.suspended_reason" :class="inp" /></label>
                    <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                    <button @click="form.put(route('super-admin.companies.update', c.id), { preserveScroll: true })" class="w-full px-3 py-2 rounded-xl bg-slate-900 text-white font-bold">حفظ</button>
                </div>
                <div class="bg-white rounded-2xl border p-4 space-y-1.5">
                    <h3 class="font-black mb-1">الاستخدام</h3>
                    <div v-for="(u, k) in usage" :key="k" class="flex justify-between"><span>{{ u.label }}</span><b class="font-mono">{{ u.used }} / {{ u.max === null || u.max < 0 ? '∞' : u.max }}</b></div>
                    <h3 class="font-black mt-3 mb-1">النطاق الخاص</h3>
                    <p class="font-mono" dir="ltr">{{ company.custom_domain || '—' }}</p>
                    <p v-if="company.custom_domain">{{ company.custom_domain_verified_at ? 'موثق ' + date(company.custom_domain_verified_at) : 'غير موثق' }}</p>
                    <button v-if="company.custom_domain && !company.custom_domain_verified_at" @click="router.post(route('super-admin.companies.verify-domain', c.id))" class="text-indigo-600 font-bold">اعتماد يدوي</button>
                </div>
                <div class="bg-white rounded-2xl border p-4">
                    <h3 class="font-black mb-2">المستخدمون ({{ users.length }})</h3>
                    <div v-for="u in users" :key="u.id" class="flex justify-between py-0.5"><span>{{ u.name }}</span><span class="text-slate-400">{{ u.email }}</span></div>
                </div>
            </div>
            <div class="grid lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-2xl border p-4">
                    <h3 class="font-black mb-2">فواتير الاشتراك</h3>
                    <div v-for="i in invoices" :key="i.id" class="flex justify-between py-1 border-b border-slate-50"><span class="font-mono">{{ i.number }} · {{ i.plan?.name_ar }}</span><span><b class="font-mono">{{ money(i.total) }}</b> {{ i.status }}</span></div>
                </div>
                <div class="bg-white rounded-2xl border p-4">
                    <h3 class="font-black mb-2">سجل التدقيق</h3>
                    <div v-for="a in audit" :key="a.id" class="py-1 border-b border-slate-50"><b>{{ a.action }}</b> — {{ a.user?.name }} <span class="text-slate-400 font-mono">{{ String(a.created_at).substring(0, 16).replace('T', ' ') }}</span><div class="text-slate-400 break-all">{{ JSON.stringify(a.details) }}</div></div>
                </div>
            </div>
        </div>
    </SuperAdminLayout>
</template>

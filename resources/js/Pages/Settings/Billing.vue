<script setup>
import { useI18n } from 'vue-i18n';
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/Documents/StatusBadge.vue';
import { money, date, dateTime } from '@/utils/format';
const { t } = useI18n();

const props = defineProps({ company: Object, plan: Object, state: String, usage: Object, plans: Array, modules: Object, invoices: Array, exports: Array, centralDomain: String, payment: { type: Object, default: () => ({}) }, paymentsEnabled: { type: Boolean, default: false } });

const payError = ref('');
const payNow = async (i) => {
    payError.value = '';
    try {
        const { data } = await window.axios.post(route('billing.invoices.pay', i.id));
        window.location.href = data.url;
    } catch (e) {
        payError.value = e.response?.data?.errors?.payment?.[0] || t('set2_settings_billing.could_not_start_the_payment');
    }
};
const cycle = ref(props.company.billing_cycle || 'monthly');
const choose = (p) => { if (confirm(t('set2_settings_billing.request_a_subscription_to_the_n', { n0: p.name_ar }))) router.post(route('billing.plan'), { plan_id: p.id, billing_cycle: cycle.value }, { preserveScroll: true }); };
const domainForm = useForm({ custom_domain: props.company.custom_domain || '' });
const stateLabel = computed(() => ({ trial: [t('set2_settings_billing.trial_period'), 'bg-sky-50 text-sky-700'], active: [t('set2_settings_billing.active_subscription'), 'bg-emerald-50 text-emerald-700'], grace: [t('set2_settings_billing.grace_period'), 'bg-amber-50 text-amber-700'], expired: [t('set2_settings_billing.expired_view_only'), 'bg-rose-50 text-rose-700'] }));
const pct = (u) => (u.max && u.max > 0 ? Math.min(100, Math.round((u.used / u.max) * 100)) : 0);
</script>

<template>
    <AppLayout>
        <Head :title="$t('set2_settings_billing.subscription')" />
        <PageHeader :title="$t('set2_settings_billing.subscription_and_billing')" :subtitle="`${company.subdomain}.${centralDomain}`" :crumbs="[{ label: $t('set2_settings_billing.settings') }, { label: $t('set2_settings_billing.subscription') }]">
            <template #actions><span :class="stateLabel[state][1]" class="px-3 py-1.5 rounded-xl text-xs font-bold">{{ stateLabel[state][0] }}</span></template>
        </PageHeader>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('set2_settings_billing.current_plan') }}</h3>
                <p class="text-base font-black text-indigo-700">{{ plan?.name_ar || $t('set2_settings_billing.no_plan') }}</p>
                <p class="text-slate-500 mt-1">{{ state === 'trial' ? $t('set2_settings_billing.trial_ends') : $t('set2_settings_billing.subscription_ends') }}: <b class="font-mono">{{ date(state === 'trial' ? company.trial_ends_at : company.subscription_ends_at) }}</b></p>
                <div class="mt-3 space-y-2">
                    <div v-for="(u, key) in usage" :key="key">
                        <div class="flex justify-between"><span>{{ u.label }}</span><span class="font-mono">{{ u.used }} / {{ u.max === null || u.max < 0 ? '∞' : u.max }}</span></div>
                        <div class="h-1.5 bg-slate-100 rounded-full"><div class="h-1.5 rounded-full" :class="pct(u) >= 90 ? 'bg-rose-500' : 'bg-indigo-500'" :style="{ width: pct(u) + '%' }"></div></div>
                    </div>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('set2_settings_billing.custom_domain') }}</h3>
                <div class="flex gap-2"><input v-model="domainForm.custom_domain" placeholder="erp.example.com" class="flex-1 rounded-xl border-slate-200 text-xs font-mono" dir="ltr" /><button v-if="$can('admin.billing.manage')" @click="domainForm.put(route('billing.domain'), { preserveScroll: true })" class="px-3 py-2 rounded-xl bg-slate-800 text-white font-bold">{{ $t('set2_settings_billing.save') }}</button></div>
                <p v-if="domainForm.errors.custom_domain" class="text-rose-600 mt-1">{{ domainForm.errors.custom_domain }}</p>
                <template v-if="company.custom_domain">
                    <p v-if="company.custom_domain_verified_at" class="mt-3 text-emerald-700 font-bold">{{ $t('set2_settings_billing.domain_verified_and_active') }}</p>
                    <div v-else class="mt-3 space-y-1">
                        <p>{{ $t('set2_settings_billing.add_the_following_records_at_you') }}</p>
                        <p class="font-mono bg-slate-50 p-2 rounded-lg break-all" dir="ltr">TXT _diwan-verify.{{ company.custom_domain }} = {{ company.custom_domain_token }}</p>
                        <p class="font-mono bg-slate-50 p-2 rounded-lg" dir="ltr">CNAME {{ company.custom_domain }} → {{ company.subdomain }}.{{ centralDomain }}</p>
                        <button v-if="$can('admin.billing.manage')" @click="router.post(route('billing.domain.verify'), {}, { preserveScroll: true })" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('set2_settings_billing.verify') }}</button>
                    </div>
                </template>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h3 class="font-black text-slate-700 mb-2">{{ $t('set2_settings_billing.a_full_copy_of_the_company_s_dat') }}</h3>
                <p class="text-slate-500 mb-2">{{ $t('set2_settings_billing.a_compressed_file_with_all_the_c') }}</p>
                <button v-if="$can('admin.data.export')" @click="router.post(route('billing.export'), {}, { preserveScroll: true })" class="px-3 py-2 rounded-xl border border-slate-200 font-bold">{{ $t('set2_settings_billing.request_a_copy') }}</button>
                <div v-for="e in exports" :key="e.id" class="flex justify-between mt-2"><span class="font-mono">{{ dateTime(e.created_at) }}</span><a v-if="e.status === 'ready'" :href="route('billing.export.download', e.id)" class="text-indigo-600 font-bold">{{ $t('set2_settings_billing.download') }}</a><span v-else>{{ e.status === 'failed' ? $t('set2_settings_billing.failed') : $t('set2_settings_billing.being_prepared') }}</span></div>
            </div>
        </div>

        <div class="flex items-center gap-2 mb-3 text-xs">
            <button @click="cycle = 'monthly'" :class="cycle === 'monthly' ? 'bg-slate-800 text-white' : 'bg-white border'" class="px-3 py-1.5 rounded-xl font-bold">{{ $t('set2_settings_billing.monthly') }}</button>
            <button @click="cycle = 'yearly'" :class="cycle === 'yearly' ? 'bg-slate-800 text-white' : 'bg-white border'" class="px-3 py-1.5 rounded-xl font-bold">{{ $t('set2_settings_billing.yearly') }}</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 text-xs">
            <div v-for="p in plans" :key="p.id" class="bg-white border rounded-2xl p-4" :class="p.id === plan?.id ? 'border-indigo-500 ring-2 ring-indigo-100' : 'border-slate-200'">
                <div class="flex justify-between"><h3 class="font-black text-sm">{{ p.name_ar }}</h3><span v-if="p.is_popular" class="text-indigo-600 font-bold">{{ $t('set2_settings_billing.most_popular') }}</span></div>
                <p class="text-lg font-black mt-1 font-mono">{{ money(cycle === 'yearly' ? p.price_yearly : p.price_monthly) }} <span class="text-xs font-normal text-slate-500">{{ $t('set2_settings_billing.sar_n_vat', { n0: cycle === 'yearly' ? 'سنة' : 'شهر' }) }}</span></p>
                <p class="text-slate-500 mt-1">{{ p.description_ar }}</p>
                <ul class="mt-2 space-y-0.5">
                    <li>المستخدمون: {{ p.max_users < 0 ? $t('set2_settings_billing.unlimited') : p.max_users }}</li><li>الفروع: {{ p.max_branches < 0 ? $t('set2_settings_billing.unlimited') : p.max_branches }}</li>
                    <li>الموظفون: {{ p.max_employees < 0 ? $t('set2_settings_billing.unlimited') : p.max_employees }}</li><li>الفواتير شهرياً: {{ p.max_invoices_per_month < 0 ? $t('set2_settings_billing.unlimited') : p.max_invoices_per_month }}</li>
                    <li class="text-slate-500">{{ p.modules ? p.modules.map((m) => modules[m]).join($t('set2_settings_billing.text')) : $t('set2_settings_billing.all_modules') }}</li>
                </ul>
                <button v-if="$can('admin.billing.manage')" @click="choose(p)" class="w-full mt-3 px-3 py-2 rounded-xl font-bold" :class="p.id === plan?.id ? 'border border-indigo-200 text-indigo-700' : 'bg-indigo-600 text-white'">{{ p.id === plan?.id ? $t('set2_settings_billing.renew') : $t('set2_settings_billing.choose') }}</button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden text-xs">
            <table class="w-full">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-3 py-2">{{ $t('set2_settings_billing.invoice') }}</th><th class="px-3 py-2">{{ $t('set2_settings_billing.plan') }}</th><th class="px-3 py-2">{{ $t('set2_settings_billing.period') }}</th><th class="px-3 py-2 text-end">{{ $t('set2_settings_billing.total') }}</th><th class="px-3 py-2">{{ $t('set2_settings_billing.status') }}</th><th class="px-3 py-2"></th></tr></thead>
                <tbody>
                    <tr v-for="i in invoices" :key="i.id" class="border-t border-slate-100"><td class="px-3 py-2 font-mono"><a :href="route('billing.invoices.show', i.id)" target="_blank" class="text-indigo-600 hover:underline">{{ i.number }}</a></td><td class="px-3 py-2 text-center">{{ i.plan?.name_ar }}</td><td class="px-3 py-2 text-center font-mono">{{ date(i.period_start) }} → {{ date(i.period_end) }}</td><td class="px-3 py-2 text-end font-mono">{{ money(i.total) }}</td><td class="px-3 py-2 text-center"><StatusBadge :status="i.status" /></td><td class="px-3 py-2 text-center"><button v-if="paymentsEnabled && i.status === 'unpaid' && $can('admin.billing.manage')" type="button" data-pay-now @click="payNow(i)" class="px-3 py-1.5 rounded-xl bg-emerald-600 text-white font-bold">{{ $t('set2_settings_billing.pay_now') }}</button></td></tr>
                    <tr v-if="!invoices.length"><td colspan="6" class="py-6 text-center text-slate-400">{{ $t('set2_settings_billing.no_subscription_invoices') }}</td></tr>
                </tbody>
            </table>
            <p v-if="payError" class="p-3 text-rose-600 font-bold">{{ payError }}</p>
            <div class="p-3 text-slate-600 space-y-1">
                <p>{{ $t('set2_settings_billing.pay_by_bank_transfer_quoting_the') }}</p>
                <p v-if="payment?.iban" class="font-bold">
                    {{ payment.bank_name }} — {{ payment.account_name || payment.name }} — IBAN: <span class="font-mono" dir="ltr">{{ payment.iban }}</span>
                </p>
                <p v-if="payment?.email">{{ $t('set2_settings_billing.to_send_the_transfer_notice') }} <a :href="`mailto:${payment.email}`" class="text-indigo-600">{{ payment.email }}</a></p>
            </div>
        </div>
    </AppLayout>
</template>

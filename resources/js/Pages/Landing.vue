<script setup>
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import BrandLogo from '@/Components/BrandLogo.vue';
import {
    CalculatorIcon, ShoppingCartIcon, DocumentCheckIcon, CubeIcon, UserGroupIcon, ComputerDesktopIcon,
    BuildingOffice2Icon, WrenchScrewdriverIcon, GlobeAltIcon, ChartBarIcon, ShieldCheckIcon, CloudArrowUpIcon,
    CheckCircleIcon, SparklesIcon, ArrowLeftIcon,
} from '@heroicons/vue/24/outline';
const { t } = useI18n();

/**
 * Public landing page of the platform domain: what the system does, the
 * plans (from the database) and the way in (sign up / log in).
 */
const props = defineProps({ plans: { type: Array, default: () => [] }, countries: { type: Number, default: 22 } });
const brand = computed(() => usePage().props.brand || {});
const yearly = ref(false);

const features = computed(() => ([
    { icon: CalculatorIcon, title: t('z_landing.integrated_accounting'), text: t('z_landing.a_ready_chart_of_accounts_automa') },
    { icon: DocumentCheckIcon, title: t('z_landing.e_invoicing'), text: t('z_landing.direct_integration_with_the_fato') },
    { icon: ShoppingCartIcon, title: t('z_landing.sales_and_purchases'), text: t('z_landing.quotations_sales_orders_invoices') },
    { icon: CubeIcon, title: t('z_landing.inventory_and_warehouses'), text: t('z_landing.items_units_batches_and_serial_n') },
    { icon: ComputerDesktopIcon, title: t('z_landing.point_of_sale'), text: t('z_landing.a_fast_cashier_with_shifts_and_c') },
    { icon: UserGroupIcon, title: t('z_landing.hr_and_payroll'), text: t('z_landing.payroll_runs_leave_and_attendanc') },
    { icon: BuildingOffice2Icon, title: t('z_landing.contracting_and_projects'), text: t('z_landing.contracts_certificates_project_c') },
    { icon: WrenchScrewdriverIcon, title: t('z_landing.equipment_and_maintenance'), text: t('z_landing.equipment_rental_operating_readi') },
    { icon: GlobeAltIcon, title: t('z_landing.branches_in_several_countries'), text: t('z_landing.each_branch_has_its_own_country') },
    { icon: ChartBarIcon, title: t('z_landing.reports_and_dashboards'), text: t('z_landing.performance_profit_receivables_a') },
    { icon: ShieldCheckIcon, title: t('z_landing.security_and_permissions'), text: t('z_landing.precise_permissions_per_role_two') },
    { icon: CloudArrowUpIcon, title: t('z_landing.backup_and_import'), text: t('z_landing.encrypted_daily_backups_and_impo') },
]));

const modules = computed(() => ({ sales: t('z_landing.sales'), pos: t('z_landing.point_of_sale'), inventory: t('z_landing.stock'), purchases: t('z_landing.purchases'), accounting: t('z_landing.accounting'), hr: t('z_landing.hr_and_payroll'), crm: t('z_landing.customer_management'), zatca: t('z_landing.e_invoicing'), projects: t('z_landing.contracting_and_projects'), equipment: t('z_landing.equipment') }));
const price = (p) => Number(yearly.value ? p.price_yearly : p.price_monthly).toLocaleString('ar-SA-u-nu-latn');
const limit = (n, unit) => (n && n < 9999 ? `${n} ${unit}` : t('z_landing.unlimited_n', { n0: unit }));

const faqs = computed(() => ([
    [t('z_landing.do_i_need_to_install_any_softwar'), t('z_landing.no_the_system_is_cloud_based_and')],
    [t('z_landing.is_the_system_approved_for_e_inv'), t('z_landing.it_supports_both_phases_the_qr_c')],
    [t('z_landing.can_i_move_my_existing_data'), t('z_landing.yes_you_import_customers_supplie')],
    [t('z_landing.what_happens_after_the_free_tria'), t('z_landing.you_choose_the_right_plan_and_ca')],
]));
</script>

<template>
    <Head :title="$t('z_landing.n_n', { n0: brand.name || 'الديوان', n1: brand.subtitle || '' })" />
    <div class="min-h-screen bg-white text-slate-800 font-sans" dir="rtl">
        <!-- Navigation -->
        <header class="sticky top-0 z-30 bg-white/85 backdrop-blur border-b border-slate-100">
            <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-3">
                <BrandLogo size="sm" :showSubtitle="false" />
                <nav class="hidden md:flex items-center gap-6 text-sm font-semibold text-slate-600">
                    <a href="#features" class="hover:text-indigo-600">{{ $t('z_landing.features') }}</a>
                    <a href="#pricing" class="hover:text-indigo-600">{{ $t('z_landing.pricing') }}</a>
                    <a href="#faq" class="hover:text-indigo-600">{{ $t('z_landing.faq') }}</a>
                </nav>
                <div class="flex items-center gap-2">
                    <Link :href="route('login')" class="px-3 py-2 text-sm font-bold text-slate-700 hover:text-indigo-600">{{ $t('z_landing.sign_in') }}</Link>
                    <Link :href="route('register')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold shadow hover:bg-indigo-700" data-cta-register>{{ $t('z_landing.start_free') }}</Link>
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative overflow-hidden bg-gradient-to-br from-indigo-50 via-white to-slate-50">
            <div class="max-w-6xl mx-auto px-4 py-16 md:py-24 grid md:grid-cols-2 gap-10 items-center">
                <div>
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold"><SparklesIcon class="w-4 h-4" /> {{ $t('z_landing.free_trial_no_payment_card') }}</span>
                    <h1 class="mt-4 text-3xl md:text-5xl font-black leading-tight text-slate-900">{{ brand.name || $t('z_landing.diwan') }}<br><span class="text-indigo-600">{{ brand.subtitle }}</span></h1>
                    <p class="mt-5 text-base md:text-lg text-slate-600 leading-relaxed">{{ $t('z_landing.n_accounting_sales_inventory_pay', { n0: brand.tagline }) }}</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link :href="route('register')" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 text-white font-bold shadow-lg hover:bg-indigo-700">{{ $t('z_landing.register_your_company_now') }} <ArrowLeftIcon class="w-4 h-4" /></Link>
                        <a href="#pricing" class="px-6 py-3 rounded-xl border border-slate-200 bg-white font-bold text-slate-700 hover:border-indigo-300">{{ $t('z_landing.view_pricing') }}</a>
                    </div>
                    <div class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500">
                        <span class="inline-flex items-center gap-1"><CheckCircleIcon class="w-5 h-5 text-emerald-500" /> {{ $t('z_landing.zatca_compliant') }}</span>
                        <span class="inline-flex items-center gap-1"><CheckCircleIcon class="w-5 h-5 text-emerald-500" /> {{ $t('z_landing.n_arab_countries', { n0: countries }) }}</span>
                        <span class="inline-flex items-center gap-1"><CheckCircleIcon class="w-5 h-5 text-emerald-500" /> {{ $t('z_landing.fully_arabic') }}</span>
                    </div>
                </div>
                <!-- Product glimpse -->
                <div class="relative">
                    <div class="absolute -inset-4 bg-indigo-200/40 blur-3xl rounded-full"></div>
                    <div class="relative bg-white rounded-3xl border border-slate-200 shadow-2xl p-5 space-y-4">
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-2xl bg-indigo-50 p-3"><p class="text-[11px] text-slate-500">{{ $t('z_landing.this_month_s_sales') }}</p><p class="font-black text-indigo-700 mt-1">284,500</p></div>
                            <div class="rounded-2xl bg-emerald-50 p-3"><p class="text-[11px] text-slate-500">{{ $t('z_landing.net_profit') }}</p><p class="font-black text-emerald-700 mt-1">61,230</p></div>
                            <div class="rounded-2xl bg-amber-50 p-3"><p class="text-[11px] text-slate-500">{{ $t('z_landing.receivable') }}</p><p class="font-black text-amber-700 mt-1">38,900</p></div>
                        </div>
                        <div class="rounded-2xl border border-slate-100 p-3">
                            <div class="flex items-end gap-1.5 h-28">
                                <div v-for="(h, i) in [40, 55, 48, 70, 62, 85, 78, 92]" :key="i" :style="{ height: h + '%' }" class="flex-1 rounded-t-lg bg-gradient-to-t from-indigo-500 to-indigo-300"></div>
                            </div>
                        </div>
                        <div class="space-y-2 text-xs">
                            <div v-for="row in [['INV-26-000148', $t('z_landing.approved_by_the_authority'), 'bg-emerald-50 text-emerald-700'], ['INV-26-000147', $t('z_landing.reported'), 'bg-emerald-50 text-emerald-700'], ['QT-26-000052', $t('z_landing.quotation_sent'), 'bg-sky-50 text-sky-700']]" :key="row[0]" class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2">
                                <span class="font-mono text-slate-600">{{ row[0] }}</span><span :class="row[2]" class="px-2 py-0.5 rounded-lg font-bold">{{ row[1] }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="max-w-6xl mx-auto px-4 py-16 md:py-20">
            <div class="text-center max-w-2xl mx-auto">
                <h2 class="text-2xl md:text-3xl font-black text-slate-900">{{ $t('z_landing.everything_your_company_needs_in') }}</h2>
                <p class="mt-3 text-slate-600">{{ $t('z_landing.connected_modules_every_document') }}</p>
            </div>
            <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <div v-for="f in features" :key="f.title" class="rounded-2xl border border-slate-100 bg-white p-5 hover:shadow-lg hover:border-indigo-100 transition">
                    <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><component :is="f.icon" class="w-6 h-6" /></div>
                    <h3 class="mt-4 font-black text-slate-900">{{ f.title }}</h3>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">{{ f.text }}</p>
                </div>
            </div>
        </section>

        <!-- Pricing -->
        <section id="pricing" class="bg-slate-50 py-16 md:py-20">
            <div class="max-w-6xl mx-auto px-4">
                <div class="text-center">
                    <h2 class="text-2xl md:text-3xl font-black text-slate-900">{{ $t('z_landing.plans_that_fit_your_company_s_si') }}</h2>
                    <p class="mt-3 text-slate-600">{{ $t('z_landing.start_with_a_free_trial_and_chan') }}</p>
                    <div class="mt-6 inline-flex rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold">
                        <button @click="yearly = false" :class="!yearly ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="px-4 py-1.5 rounded-lg">{{ $t('z_landing.monthly') }}</button>
                        <button @click="yearly = true" :class="yearly ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="px-4 py-1.5 rounded-lg">{{ $t('z_landing.yearly') }} <span class="text-[11px] opacity-80">{{ $t('z_landing.save_two_months') }}</span></button>
                    </div>
                </div>
                <div class="mt-10 grid md:grid-cols-3 gap-5" data-plans>
                    <div v-for="p in plans" :key="p.id" :class="p.is_popular ? 'border-indigo-500 ring-2 ring-indigo-500 shadow-xl' : 'border-slate-200'" class="relative rounded-3xl border bg-white p-6 flex flex-col">
                        <span v-if="p.is_popular" class="absolute -top-3 start-6 px-3 py-1 rounded-full bg-indigo-600 text-white text-xs font-bold">{{ $t('z_landing.most_popular') }}</span>
                        <h3 class="text-lg font-black text-slate-900">{{ p.name_ar }}</h3>
                        <p v-if="p.description_ar" class="mt-1 text-sm text-slate-500">{{ p.description_ar }}</p>
                        <p class="mt-5"><span class="text-4xl font-black text-slate-900">{{ price(p) }}</span> <span class="text-sm text-slate-500">{{ $t('z_landing.sar_n', { n0: yearly ? 'سنة' : 'شهر' }) }}</span></p>
                        <p class="text-[11px] text-slate-400">{{ $t('z_landing.prices_exclude_vat') }}</p>
                        <ul class="mt-6 space-y-2 text-sm text-slate-700 flex-1">
                            <li class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ limit(p.max_users, $t('z_landing.users')) }}</li>
                            <li class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ limit(p.max_branches, $t('z_landing.branches')) }}</li>
                            <li class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ limit(p.max_invoices_per_month, $t('z_landing.invoices_monthly')) }}</li>
                            <li v-for="m in p.modules || []" :key="m" class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ modules[m] || m }}</li>
                        </ul>
                        <Link :href="route('register')" :class="p.is_popular ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-slate-200 text-slate-800 hover:border-indigo-300'" class="mt-6 block text-center px-4 py-3 rounded-xl font-bold">
                            {{ $t('z_landing.start_a_n_day_trial', { n0: p.trial_days || 14 }) }}
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="max-w-3xl mx-auto px-4 py-16 md:py-20">
            <h2 class="text-2xl md:text-3xl font-black text-slate-900 text-center">{{ $t('z_landing.faq') }}</h2>
            <div class="mt-8 space-y-3">
                <details v-for="[q, a] in faqs" :key="q" class="group rounded-2xl border border-slate-200 bg-white p-4">
                    <summary class="cursor-pointer font-bold text-slate-800 list-none flex items-center justify-between">{{ q }} <span class="text-indigo-600 group-open:rotate-45 transition text-xl leading-none">+</span></summary>
                    <p class="mt-3 text-sm text-slate-600 leading-relaxed">{{ a }}</p>
                </details>
            </div>
        </section>

        <!-- Final call to action -->
        <section class="px-4 pb-16">
            <div class="max-w-5xl mx-auto rounded-3xl bg-gradient-to-l from-indigo-600 to-violet-600 text-white p-8 md:p-12 text-center shadow-xl">
                <h2 class="text-2xl md:text-3xl font-black">{{ $t('z_landing.ready_to_move_your_company_s_man', { n0: brand.name || 'الديوان' }) }}</h2>
                <p class="mt-3 text-indigo-100">{{ $t('z_landing.register_in_two_minutes_import_y') }}</p>
                <Link :href="route('register')" class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-white text-indigo-700 font-black shadow hover:bg-indigo-50">{{ $t('z_landing.start_your_free_trial') }} <ArrowLeftIcon class="w-4 h-4" /></Link>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-slate-100 py-8">
            <div class="max-w-6xl mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-3 text-sm text-slate-500">
                <BrandLogo size="sm" :showSubtitle="false" />
                <div class="flex gap-4">
                    <a :href="route('legal.show', 'terms')" class="hover:text-indigo-600">{{ $t('z_landing.terms_and_conditions') }}</a>
                    <a :href="route('legal.show', 'privacy')" class="hover:text-indigo-600">{{ $t('z_landing.privacy_policy') }}</a>
                    <Link :href="route('login')" class="hover:text-indigo-600">{{ $t('z_landing.sign_in') }}</Link>
                </div>
                <span>© {{ new Date().getFullYear() }} {{ brand.name || $t('z_landing.diwan') }}</span>
            </div>
        </footer>
    </div>
</template>

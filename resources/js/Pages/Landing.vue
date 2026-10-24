<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import BrandLogo from '@/Components/BrandLogo.vue';
import {
    CalculatorIcon, ShoppingCartIcon, DocumentCheckIcon, CubeIcon, UserGroupIcon, ComputerDesktopIcon,
    BuildingOffice2Icon, WrenchScrewdriverIcon, GlobeAltIcon, ChartBarIcon, ShieldCheckIcon, CloudArrowUpIcon,
    CheckCircleIcon, SparklesIcon, ArrowLeftIcon,
} from '@heroicons/vue/24/outline';

/**
 * Public landing page of the platform domain: what the system does, the
 * plans (from the database) and the way in (sign up / log in).
 */
const props = defineProps({ plans: { type: Array, default: () => [] }, countries: { type: Number, default: 22 } });
const brand = computed(() => usePage().props.brand || {});
const yearly = ref(false);

const features = [
    { icon: CalculatorIcon, title: 'محاسبة متكاملة', text: 'دليل حسابات جاهز، قيود آلية من كل مستند، قوائم مالية وتدفقات نقدية، وإقفال الفترات.' },
    { icon: DocumentCheckIcon, title: 'الفوترة الإلكترونية', text: 'ربط مباشر مع منصة «فاتورة» بالمرحلتين: توقيع الفواتير واعتمادها والإبلاغ عنها آلياً.' },
    { icon: ShoppingCartIcon, title: 'المبيعات والمشتريات', text: 'عروض أسعار، أوامر بيع، فواتير ومرتجعات، وكشوف حساب ترسلها للعميل برابط.' },
    { icon: CubeIcon, title: 'المخزون والمستودعات', text: 'أصناف ووحدات ودفعات وأرقام تسلسلية، تحويلات وجرد، وتكلفة دقيقة لكل حركة.' },
    { icon: ComputerDesktopIcon, title: 'نقاط البيع', text: 'كاشير سريع بورديات وأدراج نقدية، يعمل مع فواتير مبسطة معتمدة.' },
    { icon: UserGroupIcon, title: 'الموارد البشرية والرواتب', text: 'مسيرات رواتب، إجازات وحضور، نهاية خدمة، وملفات مُدد وحماية الأجور للبنوك.' },
    { icon: BuildingOffice2Icon, title: 'المقاولات والمشاريع', text: 'عقود ومستخلصات وتكاليف المشاريع ومقاولو الباطن والاستقطاعات.' },
    { icon: WrenchScrewdriverIcon, title: 'المعدات والصيانة', text: 'تأجير المعدات وقراءات التشغيل وأوامر الصيانة وربطها بالأصول.' },
    { icon: GlobeAltIcon, title: 'فروع في عدة دول', text: 'لكل فرع دولته وعملته وضريبته، مع ملخص ضريبي لكل دولة.' },
    { icon: ChartBarIcon, title: 'تقارير ولوحات قيادة', text: 'مؤشرات الأداء والأرباح والذمم وأعمار الديون في لحظتها.' },
    { icon: ShieldCheckIcon, title: 'أمان وصلاحيات', text: 'صلاحيات دقيقة لكل دور، تحقق ثنائي، وسجل تدقيق لكل تعديل.' },
    { icon: CloudArrowUpIcon, title: 'نسخ احتياطي واستيراد', text: 'نسخ يومية مشفرة، واستيراد بياناتك وأرصدتك الافتتاحية من Excel.' },
];

const modules = { sales: 'المبيعات', pos: 'نقاط البيع', inventory: 'المخزون', purchases: 'المشتريات', accounting: 'المحاسبة', hr: 'الموارد البشرية والرواتب', crm: 'إدارة العملاء', zatca: 'الفوترة الإلكترونية', projects: 'المقاولات والمشاريع', equipment: 'المعدات' };
const price = (p) => Number(yearly.value ? p.price_yearly : p.price_monthly).toLocaleString('ar-SA');
const limit = (n, unit) => (n && n < 9999 ? `${n} ${unit}` : `${unit} بلا حد`);

const faqs = [
    ['هل أحتاج تثبيت أي برنامج؟', 'لا. النظام سحابي يعمل من المتصفح على الحاسب والجوال، ولكل منشأة رابطها الخاص.'],
    ['هل النظام معتمد للفوترة الإلكترونية في السعودية؟', 'يدعم المرحلتين: رمز QR في المرحلة الأولى، والربط والتكامل مع منصة «فاتورة» في المرحلة الثانية.'],
    ['هل أستطيع نقل بياناتي الحالية؟', 'نعم، تستورد العملاء والموردين والأصناف والأرصدة الافتتاحية والفواتير من ملفات Excel بقوالب جاهزة.'],
    ['ماذا يحدث بعد انتهاء التجربة المجانية؟', 'تختار الباقة المناسبة وتكمل على نفس بياناتك دون أي فقد.'],
];
</script>

<template>
    <Head :title="`${brand.name || 'الديوان'} — ${brand.subtitle || ''}`" />
    <div class="min-h-screen bg-white text-slate-800 font-sans" dir="rtl">
        <!-- Navigation -->
        <header class="sticky top-0 z-30 bg-white/85 backdrop-blur border-b border-slate-100">
            <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-3">
                <BrandLogo size="sm" :showSubtitle="false" />
                <nav class="hidden md:flex items-center gap-6 text-sm font-semibold text-slate-600">
                    <a href="#features" class="hover:text-indigo-600">المزايا</a>
                    <a href="#pricing" class="hover:text-indigo-600">الأسعار</a>
                    <a href="#faq" class="hover:text-indigo-600">الأسئلة الشائعة</a>
                </nav>
                <div class="flex items-center gap-2">
                    <Link :href="route('login')" class="px-3 py-2 text-sm font-bold text-slate-700 hover:text-indigo-600">تسجيل الدخول</Link>
                    <Link :href="route('register')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold shadow hover:bg-indigo-700" data-cta-register>ابدأ مجاناً</Link>
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative overflow-hidden bg-gradient-to-br from-indigo-50 via-white to-slate-50">
            <div class="max-w-6xl mx-auto px-4 py-16 md:py-24 grid md:grid-cols-2 gap-10 items-center">
                <div>
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold"><SparklesIcon class="w-4 h-4" /> تجربة مجانية بلا بطاقة دفع</span>
                    <h1 class="mt-4 text-3xl md:text-5xl font-black leading-tight text-slate-900">{{ brand.name || 'الديوان' }}<br><span class="text-indigo-600">{{ brand.subtitle }}</span></h1>
                    <p class="mt-5 text-base md:text-lg text-slate-600 leading-relaxed">{{ brand.tagline }}. محاسبة ومبيعات ومخزون ورواتب وفوترة إلكترونية معتمدة، في نظام عربي واحد يعمل من أي مكان.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link :href="route('register')" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 text-white font-bold shadow-lg hover:bg-indigo-700">سجّل منشأتك الآن <ArrowLeftIcon class="w-4 h-4" /></Link>
                        <a href="#pricing" class="px-6 py-3 rounded-xl border border-slate-200 bg-white font-bold text-slate-700 hover:border-indigo-300">عرض الأسعار</a>
                    </div>
                    <div class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500">
                        <span class="inline-flex items-center gap-1"><CheckCircleIcon class="w-5 h-5 text-emerald-500" /> متوافق مع هيئة الزكاة</span>
                        <span class="inline-flex items-center gap-1"><CheckCircleIcon class="w-5 h-5 text-emerald-500" /> {{ countries }} دولة عربية</span>
                        <span class="inline-flex items-center gap-1"><CheckCircleIcon class="w-5 h-5 text-emerald-500" /> عربي بالكامل</span>
                    </div>
                </div>
                <!-- Product glimpse -->
                <div class="relative">
                    <div class="absolute -inset-4 bg-indigo-200/40 blur-3xl rounded-full"></div>
                    <div class="relative bg-white rounded-3xl border border-slate-200 shadow-2xl p-5 space-y-4">
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-2xl bg-indigo-50 p-3"><p class="text-[11px] text-slate-500">مبيعات الشهر</p><p class="font-black text-indigo-700 mt-1">284,500</p></div>
                            <div class="rounded-2xl bg-emerald-50 p-3"><p class="text-[11px] text-slate-500">صافي الربح</p><p class="font-black text-emerald-700 mt-1">61,230</p></div>
                            <div class="rounded-2xl bg-amber-50 p-3"><p class="text-[11px] text-slate-500">مستحق التحصيل</p><p class="font-black text-amber-700 mt-1">38,900</p></div>
                        </div>
                        <div class="rounded-2xl border border-slate-100 p-3">
                            <div class="flex items-end gap-1.5 h-28">
                                <div v-for="(h, i) in [40, 55, 48, 70, 62, 85, 78, 92]" :key="i" :style="{ height: h + '%' }" class="flex-1 rounded-t-lg bg-gradient-to-t from-indigo-500 to-indigo-300"></div>
                            </div>
                        </div>
                        <div class="space-y-2 text-xs">
                            <div v-for="row in [['INV-26-000148', 'معتمدة من الهيئة', 'bg-emerald-50 text-emerald-700'], ['INV-26-000147', 'مبلغ عنها', 'bg-emerald-50 text-emerald-700'], ['QT-26-000052', 'عرض سعر مرسل', 'bg-sky-50 text-sky-700']]" :key="row[0]" class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2">
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
                <h2 class="text-2xl md:text-3xl font-black text-slate-900">كل ما تحتاجه منشأتك في مكان واحد</h2>
                <p class="mt-3 text-slate-600">وحدات مترابطة: كل مستند يُنشئ قيده المحاسبي وحركته المخزنية تلقائياً، فتبقى أرقامك متطابقة دائماً.</p>
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
                    <h2 class="text-2xl md:text-3xl font-black text-slate-900">باقات تناسب حجم منشأتك</h2>
                    <p class="mt-3 text-slate-600">ابدأ بتجربة مجانية، وغيّر باقتك في أي وقت.</p>
                    <div class="mt-6 inline-flex rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold">
                        <button @click="yearly = false" :class="!yearly ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="px-4 py-1.5 rounded-lg">شهري</button>
                        <button @click="yearly = true" :class="yearly ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="px-4 py-1.5 rounded-lg">سنوي <span class="text-[11px] opacity-80">(وفّر شهرين)</span></button>
                    </div>
                </div>
                <div class="mt-10 grid md:grid-cols-3 gap-5" data-plans>
                    <div v-for="p in plans" :key="p.id" :class="p.is_popular ? 'border-indigo-500 ring-2 ring-indigo-500 shadow-xl' : 'border-slate-200'" class="relative rounded-3xl border bg-white p-6 flex flex-col">
                        <span v-if="p.is_popular" class="absolute -top-3 start-6 px-3 py-1 rounded-full bg-indigo-600 text-white text-xs font-bold">الأكثر طلباً</span>
                        <h3 class="text-lg font-black text-slate-900">{{ p.name_ar }}</h3>
                        <p v-if="p.description_ar" class="mt-1 text-sm text-slate-500">{{ p.description_ar }}</p>
                        <p class="mt-5"><span class="text-4xl font-black text-slate-900">{{ price(p) }}</span> <span class="text-sm text-slate-500">ر.س / {{ yearly ? 'سنة' : 'شهر' }}</span></p>
                        <p class="text-[11px] text-slate-400">الأسعار لا تشمل ضريبة القيمة المضافة</p>
                        <ul class="mt-6 space-y-2 text-sm text-slate-700 flex-1">
                            <li class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ limit(p.max_users, 'مستخدمين') }}</li>
                            <li class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ limit(p.max_branches, 'فروع') }}</li>
                            <li class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ limit(p.max_invoices_per_month, 'فاتورة شهرياً') }}</li>
                            <li v-for="m in p.modules || []" :key="m" class="flex gap-2"><CheckCircleIcon class="w-5 h-5 text-indigo-500 shrink-0" /> {{ modules[m] || m }}</li>
                        </ul>
                        <Link :href="route('register')" :class="p.is_popular ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-slate-200 text-slate-800 hover:border-indigo-300'" class="mt-6 block text-center px-4 py-3 rounded-xl font-bold">
                            ابدأ تجربة {{ p.trial_days || 14 }} يوماً
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="max-w-3xl mx-auto px-4 py-16 md:py-20">
            <h2 class="text-2xl md:text-3xl font-black text-slate-900 text-center">الأسئلة الشائعة</h2>
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
                <h2 class="text-2xl md:text-3xl font-black">جاهز تنقل إدارة منشأتك إلى {{ brand.name || 'الديوان' }}؟</h2>
                <p class="mt-3 text-indigo-100">سجّل في دقيقتين، واستورد بياناتك، وابدأ إصدار فواتيرك الإلكترونية اليوم.</p>
                <Link :href="route('register')" class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-white text-indigo-700 font-black shadow hover:bg-indigo-50">ابدأ تجربتك المجانية <ArrowLeftIcon class="w-4 h-4" /></Link>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-slate-100 py-8">
            <div class="max-w-6xl mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-3 text-sm text-slate-500">
                <BrandLogo size="sm" :showSubtitle="false" />
                <div class="flex gap-4">
                    <a :href="route('legal.show', 'terms')" class="hover:text-indigo-600">الشروط والأحكام</a>
                    <a :href="route('legal.show', 'privacy')" class="hover:text-indigo-600">سياسة الخصوصية</a>
                    <Link :href="route('login')" class="hover:text-indigo-600">تسجيل الدخول</Link>
                </div>
                <span>© {{ new Date().getFullYear() }} {{ brand.name || 'الديوان' }}</span>
            </div>
        </footer>
    </div>
</template>

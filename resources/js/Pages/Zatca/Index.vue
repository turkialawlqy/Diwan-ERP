<script setup>
import { ask } from '@/composables/useDialog';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { date } from '@/utils/format';
import { PlusIcon, ShieldCheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ taxDefaults: Object, vatCategories: Object, exemptionReasons: Object, devices: Array, documents: Object, stats: Object, overdue: Number, background: Object, seller: Object, missing: Array, branches: Array, phase: String, filters: Object });

const sellerForm = useForm({
    tax_number: props.seller.tax_number || '', commercial_registration: props.seller.commercial_registration || '',
    seller_street: props.seller.seller_street || '', seller_building_number: props.seller.seller_building_number || '',
    seller_additional_number: props.seller.seller_additional_number || '', seller_district: props.seller.seller_district || '',
    seller_city: props.seller.seller_city || '', seller_postal_code: props.seller.seller_postal_code || '',
});
const saveSeller = () => sellerForm.put(route('zatca.seller'), { preserveScroll: true });
const taxForm = useForm({ zero_category: props.taxDefaults.zero_category || 'Z', Z: props.taxDefaults.Z || '', E: props.taxDefaults.E || '', O: props.taxDefaults.O || '', out_of_scope_reason: props.taxDefaults.out_of_scope_reason || '' });

const deviceOpen = ref(false);
const deviceForm = useForm({ name: '', branch_id: '', environment: 'sandbox', invoice_types: '1100', business_category: 'Trading' });
const saveDevice = () => deviceForm.post(route('zatca.devices.store'), { onSuccess: () => { deviceOpen.value = false; deviceForm.reset(); } });

const otp = ref({});
const onboard = (device) => router.post(route('zatca.devices.onboard', device.id), { otp: otp.value[device.id] || '' }, { preserveScroll: true });
const renew = async (device) => {
    const code = await ask('أدخل رمز OTP من بوابة «فاتورة» لتجديد الشهادة:', { title: 'تجديد شهادة الجهاز', type: 'password', required: true, minLength: 6 });
    if (code) router.post(route('zatca.devices.renew', device.id), { otp: code });
};
const revoke = (device) => { if (confirm('إيقاف الجهاز؟ لن تُوقّع به فواتير جديدة.')) router.post(route('zatca.devices.revoke', device.id)); };
const sending = ref(false);
const resubmit = (doc) => router.post(route('zatca.documents.resubmit', doc.id), {}, { preserveScroll: true, onStart: () => { sending.value = true; }, onFinish: () => { sending.value = false; } });
const submitPending = () => router.post(route('zatca.documents.submit-pending'), {}, { preserveScroll: true, onStart: () => { sending.value = true; }, onFinish: () => { sending.value = false; } });
const waiting = () => (props.stats.pending || 0) + (props.stats.failed || 0);
const filter = (status) => router.get(route('zatca.index'), { status: status || undefined }, { preserveState: true, preserveScroll: true });

const deviceStatus = { new: ['بانتظار OTP', 'bg-slate-100 text-slate-600'], compliance: ['فحوصات الامتثال', 'bg-amber-50 text-amber-700'], active: ['مفعل', 'bg-emerald-50 text-emerald-700'], revoked: ['موقوف', 'bg-rose-50 text-rose-700'] };
const docStatus = { pending: ['بانتظار الإرسال', 'text-amber-600'], submitting: ['جارٍ الإرسال', 'text-sky-600'], cleared: ['معتمدة (Cleared)', 'text-emerald-600'], reported: ['مبلغ عنها', 'text-emerald-600'], warning: ['مقبولة بتحذيرات', 'text-amber-600'], rejected: ['مرفوضة', 'text-rose-600'], failed: ['تعذر الإرسال', 'text-rose-600'], superseded: ['مُستبدل (أُعيد توقيعه)', 'text-slate-400'] };
const envLabel = { sandbox: 'بيئة المطورين', simulation: 'المحاكاة', production: 'الإنتاج' };
const typeLabel = { '388': 'فاتورة', '381': 'إشعار دائن', '383': 'إشعار مدين' };
</script>

<template>
    <AppLayout>
        <Head title="الفوترة الإلكترونية" />
        <PageHeader title="الفوترة الإلكترونية (فاتورة)" subtitle="الربط مع هيئة الزكاة والضريبة والجمارك — توقيع XML، تسلسل الفواتير، الاعتماد والإبلاغ" :crumbs="[{ label: 'الإعدادات' }, { label: 'الفوترة الإلكترونية' }]">
            <template #actions>
                <span :class="phase === 'phase2' ? 'bg-indigo-600' : 'bg-emerald-600'" class="px-3 py-1.5 rounded-xl text-white text-xs font-bold inline-flex items-center gap-1"><ShieldCheckIcon class="w-4 h-4" /> {{ phase === 'phase2' ? 'المرحلة الثانية: الربط والتكامل' : 'المرحلة الأولى: الإصدار والحفظ' }}</span>
            </template>
        </PageHeader>

        <div v-if="background && (background.stuck || (!background.scheduler_ok && waiting()))" class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex flex-wrap items-center gap-3" data-background-warning>
            <ExclamationTriangleIcon class="w-5 h-5 shrink-0" />
            <div class="flex-1 min-w-[16rem] space-y-1">
                <p class="font-bold">الإرسال التلقائي للهيئة لا يعمل على هذا الخادم<span v-if="background.stuck"> — {{ background.stuck }} مستند لم يُرسل منذ أكثر من 15 دقيقة</span>.</p>
                <p>لتشغيله دائماً: شغّل <span class="font-mono" dir="ltr">deploy\windows\install-auto-update.ps1</span> كمسؤول (Run as administrator)، وتأكد أن <span class="font-mono" dir="ltr">QUEUE_CONNECTION=database</span> في ملف ‎.env. وإلى ذلك الحين أرسلها يدوياً من هنا.</p>
            </div>
            <button v-if="$can('admin.zatca.manage')" type="button" @click="submitPending" :disabled="sending" class="px-3 py-2 rounded-xl bg-amber-600 text-white font-bold disabled:opacity-50">{{ sending ? 'جارٍ الإرسال...' : 'إرسال المعلّق الآن' }}</button>
        </div>

        <div v-if="overdue" class="mb-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-center gap-2">
            <ExclamationTriangleIcon class="w-5 h-5" /> {{ overdue }} فاتورة مبسطة تجاوزت مهلة الإبلاغ (24 ساعة). تحقق من الاتصال وأعد الإرسال.
        </div>

        <div class="grid lg:grid-cols-3 gap-4 mb-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 lg:col-span-1">
                <h3 class="text-xs font-black text-slate-700 mb-1">بيانات البائع (إلزامية في XML)</h3>
                <p v-if="missing.length" class="text-[11px] text-rose-600 mb-2">ناقص: {{ missing.join('، ') }}</p>
                <div class="grid grid-cols-2 gap-2">
                    <Field label="الرقم الضريبي" :error="sellerForm.errors.tax_number" class="col-span-2"><input v-model="sellerForm.tax_number" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <Field label="السجل التجاري" :error="sellerForm.errors.commercial_registration" class="col-span-2"><input v-model="sellerForm.commercial_registration" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <Field label="الشارع" :error="sellerForm.errors.seller_street" class="col-span-2"><input v-model="sellerForm.seller_street" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="رقم المبنى" :error="sellerForm.errors.seller_building_number"><input v-model="sellerForm.seller_building_number" maxlength="4" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <Field label="الرقم الإضافي" :error="sellerForm.errors.seller_additional_number"><input v-model="sellerForm.seller_additional_number" maxlength="4" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                    <Field label="الحي" :error="sellerForm.errors.seller_district"><input v-model="sellerForm.seller_district" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="المدينة" :error="sellerForm.errors.seller_city"><input v-model="sellerForm.seller_city" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="الرمز البريدي" :error="sellerForm.errors.seller_postal_code"><input v-model="sellerForm.seller_postal_code" maxlength="5" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                </div>
                <button v-if="$can('admin.zatca.manage')" @click="saveSeller" :disabled="sellerForm.processing" class="mt-3 w-full px-4 py-2 rounded-xl bg-slate-800 text-white text-xs font-bold">حفظ بيانات البائع</button>

                <h3 class="text-xs font-black text-slate-700 mt-5 mb-1">الإعفاء والنسبة الصفرية</h3>
                <p class="text-[11px] text-slate-500 mb-2">يُستخدم للأسطر بنسبة 0% التي لا يحدد صنفها سبباً خاصاً. الأفضل ضبط الفئة والسبب في بطاقة كل صنف.</p>
                <div class="space-y-2">
                    <Field label="فئة الأسطر بنسبة 0% افتراضياً"><select v-model="taxForm.zero_category" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="k in ['Z', 'E', 'O']" :key="k" :value="k">{{ k }} — {{ vatCategories[k] }}</option></select></Field>
                    <Field v-for="k in ['Z', 'E', 'O']" :key="k" :label="`السبب الافتراضي — ${vatCategories[k]}`" :error="taxForm.errors[k]">
                        <select v-model="taxForm[k]" class="w-full rounded-xl border-slate-200 text-xs"><option value="">— بدون (يُطلب عند الإصدار) —</option><option v-for="(r, code) in exemptionReasons[k]" :key="code" :value="code">{{ code }} — {{ r.ar }}</option></select>
                    </Field>
                    <Field label="نص سبب «خارج النطاق»"><input v-model="taxForm.out_of_scope_reason" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </div>
                <button v-if="$can('admin.zatca.manage')" @click="taxForm.put(route('zatca.tax-defaults'), { preserveScroll: true })" class="mt-3 w-full px-4 py-2 rounded-xl bg-slate-800 text-white text-xs font-bold">حفظ إعدادات الإعفاء</button>
            </div>

            <div class="lg:col-span-2 space-y-3">
                <div class="flex justify-between items-center">
                    <h3 class="text-xs font-black text-slate-700">أجهزة الفوترة (EGS)</h3>
                    <button v-if="$can('admin.zatca.manage')" @click="deviceOpen = true" :disabled="missing.length" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40"><PlusIcon class="w-4 h-4" /> جهاز جديد</button>
                </div>
                <div v-for="d in devices" :key="d.id" class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
                    <div class="flex flex-wrap justify-between gap-2">
                        <div>
                            <b class="text-slate-800">{{ d.name }}</b> <span class="text-slate-400">· {{ envLabel[d.environment] }} · {{ d.branch?.name_ar || 'كل الفروع' }}</span>
                            <div class="text-slate-400 font-mono text-[10px] mt-0.5">{{ d.serial_number }}</div>
                        </div>
                        <span :class="deviceStatus[d.status][1]" class="px-2 py-0.5 rounded-lg font-bold h-fit">{{ deviceStatus[d.status][0] }}</span>
                    </div>
                    <div v-if="d.status === 'active'" class="grid grid-cols-3 gap-2 mt-3 text-slate-600">
                        <div>عداد الفواتير (ICV): <b class="font-mono">{{ d.icv }}</b></div>
                        <div>انتهاء الشهادة: <b class="font-mono">{{ date(d.certificate_expires_at) }}</b></div>
                        <div>تاريخ الربط: <b class="font-mono">{{ date(d.onboarded_at) }}</b></div>
                    </div>
                    <div v-if="d.compliance_checks" class="flex flex-wrap gap-1 mt-3">
                        <span v-for="(r, key) in d.compliance_checks" :key="key" :class="r.status === 'passed' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'" class="px-2 py-0.5 rounded-lg font-mono" :title="(r.messages || []).map((m) => m.message).join('\n')">{{ key }}</span>
                    </div>
                    <div v-if="$can('admin.zatca.manage')" class="flex flex-wrap gap-2 mt-3 items-center">
                        <template v-if="d.status === 'new'">
                            <input v-model="otp[d.id]" placeholder="رمز OTP من بوابة فاتورة" class="rounded-xl border-slate-200 text-xs font-mono w-48" />
                            <button @click="onboard(d)" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold">ربط الجهاز</button>
                        </template>
                        <button v-if="d.status === 'compliance'" @click="onboard(d)" class="px-3 py-1.5 rounded-xl bg-amber-600 text-white font-bold">إعادة فحوصات الامتثال</button>
                        <button v-if="d.status === 'active'" @click="renew(d)" class="px-3 py-1.5 rounded-xl border border-slate-200 font-bold">تجديد الشهادة</button>
                        <button v-if="d.status !== 'revoked'" @click="revoke(d)" class="px-3 py-1.5 rounded-xl border border-rose-200 text-rose-600 font-bold">إيقاف</button>
                    </div>
                </div>
                <p v-if="!devices.length" class="bg-white border border-dashed border-slate-300 rounded-2xl p-6 text-center text-xs text-slate-500">
                    لا توجد أجهزة مربوطة: الفواتير تصدر برمز QR للمرحلة الأولى. أضف جهازاً واحصل على رمز OTP من بوابة فاتورة (fatoora.zatca.gov.sa) لتفعيل المرحلة الثانية.
                </p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="flex flex-wrap gap-2 p-3 border-b border-slate-100 text-xs">
                <button @click="filter(null)" :class="!filters.status ? 'bg-slate-800 text-white' : 'bg-slate-50'" class="px-3 py-1 rounded-lg font-bold">الكل</button>
                <button v-for="(label, key) in docStatus" :key="key" @click="filter(key)" :class="filters.status === key ? 'bg-slate-800 text-white' : 'bg-slate-50'" class="px-3 py-1 rounded-lg font-bold">{{ label[0] }} <span class="font-mono">({{ stats[key] || 0 }})</span></button>
                <button v-if="waiting() && $can('admin.zatca.manage')" type="button" @click="submitPending" :disabled="sending" class="ms-auto px-3 py-1 rounded-lg bg-indigo-600 text-white font-bold disabled:opacity-50" data-submit-pending>{{ sending ? 'جارٍ الإرسال...' : `إرسال المعلّق الآن (${waiting()})` }}</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">المستند</th><th class="px-4 py-2">النوع</th><th class="px-4 py-2">الجهاز</th><th class="px-4 py-2">ICV</th><th class="px-4 py-2">الإرسال</th><th class="px-4 py-2">الحالة</th><th class="px-4 py-2">المحاولات</th><th class="text-start px-4 py-2">الرسائل</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="doc in documents.data" :key="doc.id" class="border-t border-slate-100">
                            <td class="px-4 py-2 font-mono">{{ doc.document_number }}</td>
                            <td class="px-4 py-2 text-center">{{ typeLabel[doc.type_code] }} {{ doc.subtype === '0200000' ? 'مبسطة' : 'ضريبية' }}</td>
                            <td class="px-4 py-2 text-center font-mono text-slate-500">{{ doc.device?.name }}<span v-if="doc.device && doc.device.status !== 'active'" class="block font-sans text-3xs text-rose-500">غير مفعّل</span></td>
                            <td class="px-4 py-2 text-center font-mono">{{ doc.icv }}</td>
                            <td class="px-4 py-2 text-center">{{ doc.submission === 'clearance' ? 'اعتماد مسبق' : 'إبلاغ' }}</td>
                            <td class="px-4 py-2 text-center font-bold" :class="docStatus[doc.status]?.[1]">{{ docStatus[doc.status]?.[0] || doc.status }}</td>
                            <td class="px-4 py-2 text-center font-mono">{{ doc.attempts }}<span v-if="doc.status === 'pending' && !doc.attempts" class="block font-sans text-3xs text-slate-400">لم يُرسل بعد</span></td>
                            <td class="px-4 py-2 text-[11px] text-slate-500 max-w-xs">
                                <div v-for="(m, i) in [...(doc.errors || []), ...(doc.warnings || [])]" :key="i">{{ m.code ? m.code + ': ' : '' }}{{ m.message }}</div>
                                <div v-for="(h, i) in doc.hints || []" :key="'h' + i" class="mt-1 p-1.5 rounded-lg bg-indigo-50 text-indigo-800 font-bold" data-zatca-hint>💡 {{ h }}</div>
                            </td>
                            <td class="px-4 py-2 text-end whitespace-nowrap">
                                <a v-if="$can('admin.zatca.export') || $can('admin.zatca.manage')" :href="route('zatca.documents.xml', doc.id)" class="text-indigo-600 font-bold me-3">XML</a>
                                <button v-if="['failed', 'pending'].includes(doc.status) && $can('admin.zatca.manage')" @click="resubmit(doc)" :disabled="sending" class="text-amber-600 font-bold disabled:opacity-50">{{ doc.needs_restamp ? 'إعادة التوقيع على الجهاز الحالي' : (doc.attempts ? 'إعادة الإرسال' : 'إرسال الآن') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!documents.data.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ $t('ui.no_data') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="documents" />
        </div>

        <Dialog :show="deviceOpen" title="جهاز فوترة جديد (EGS)" @close="deviceOpen = false">
            <div class="grid grid-cols-2 gap-3">
                <Field label="اسم الجهاز (إنجليزي)" :error="deviceForm.errors.name"><input v-model="deviceForm.name" placeholder="POS-RIYADH-1" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field label="الفرع"><select v-model="deviceForm.branch_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">كل الفروع</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name_ar }}</option></select></Field>
                <Field label="البيئة"><select v-model="deviceForm.environment" class="w-full rounded-xl border-slate-200 text-xs"><option value="sandbox">بيئة المطورين (تجربة)</option><option value="simulation">المحاكاة</option><option value="production">الإنتاج</option></select></Field>
                <Field label="أنواع الفواتير"><select v-model="deviceForm.invoice_types" class="w-full rounded-xl border-slate-200 text-xs"><option value="1100">ضريبية ومبسطة</option><option value="1000">ضريبية (B2B) فقط</option><option value="0100">مبسطة (B2C) فقط</option></select></Field>
                <Field label="نشاط المنشأة (إنجليزي)" :error="deviceForm.errors.business_category" class="col-span-2"><input v-model="deviceForm.business_category" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <p v-if="deviceForm.errors.device" class="text-xs text-rose-600 mt-2">{{ deviceForm.errors.device }}</p>
            <p class="text-[11px] text-slate-500 mt-3">يُنشأ مفتاح ECDSA (secp256k1) خاص بالجهاز ويُحفظ مشفراً، ثم طلب شهادة (CSR) بصيغة الهيئة.</p>
            <template #footer><button @click="saveDevice" :disabled="deviceForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">إنشاء الجهاز</button></template>
        </Dialog>
    </AppLayout>
</template>

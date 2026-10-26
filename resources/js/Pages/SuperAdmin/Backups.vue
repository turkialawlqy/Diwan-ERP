<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';

const props = defineProps({ runs: Array, files: Array, config: Object });
const flash = computed(() => usePage().props.flash || {});
const lastOk = computed(() => props.runs.find((r) => r.type === 'backup' && r.status === 'ok'));
const lastVerify = computed(() => props.runs.find((r) => r.type === 'verify'));
const mb = (b) => (b == null ? '—' : (b / 1048576).toFixed(2) + ' MB');
const time = (t) => String(t || '').substring(0, 16).replace('T', ' ');
const run = (verify) => router.post(route('super-admin.backups.run'), { verify }, { preserveScroll: true });
const ageHours = computed(() => (lastOk.value ? Math.round((Date.now() - new Date(lastOk.value.created_at)) / 36e5) : null));
</script>

<template>
    <SuperAdminLayout>
        <Head title="النسخ الاحتياطي" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 space-y-4 text-xs">
            <div v-if="flash.success" class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 font-bold text-emerald-800">{{ flash.success }}</div>
            <div v-if="flash.error" class="rounded-xl bg-rose-50 border border-rose-200 p-3 font-bold text-rose-800">{{ flash.error }}</div>

            <div class="grid md:grid-cols-4 gap-3">
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">آخر نسخة ناجحة</div>
                    <div class="text-base font-black mt-1" :class="ageHours === null || ageHours > 26 ? 'text-rose-600' : 'text-slate-800'">{{ lastOk ? time(lastOk.created_at) : 'لا توجد' }}</div>
                    <div v-if="ageHours !== null && ageHours > 26" class="text-rose-600 mt-1">أقدم من يوم — تحقق من المجدول</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">آخر اختبار استعادة</div>
                    <div class="text-base font-black mt-1" :class="lastVerify?.status === 'ok' ? 'text-emerald-700' : 'text-rose-600'">{{ lastVerify ? (lastVerify.status === 'ok' ? 'ناجح' : 'فشل') + ' · ' + time(lastVerify.created_at) : 'لم يُجرَ بعد' }}</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">التشفير</div>
                    <div class="text-base font-black mt-1" :class="config.encrypted ? 'text-emerald-700' : 'text-rose-600'">{{ config.encrypted ? 'AES-256 مفعّل' : 'غير مفعّل — اضبط BACKUP_PASSWORD' }}</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-slate-500">التخزين والاحتفاظ</div>
                    <div class="font-bold mt-1">قرص {{ config.disk }} · يومي {{ config.keep.daily }} / أسبوعي {{ config.keep.weekly }} / شهري {{ config.keep.monthly }}</div>
                    <div class="text-slate-400 mt-1">{{ config.notify ? 'تنبيه الفشل بالبريد مفعّل' : 'لا يوجد بريد تنبيه (BACKUP_NOTIFY_EMAIL)' }}</div>
                </div>
            </div>

            <div class="flex gap-2">
                <button @click="run(false)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">نسخ احتياطي الآن</button>
                <button @click="run(true)" class="px-4 py-2 rounded-xl border border-slate-300 bg-white font-bold">اختبار استعادة آخر نسخة</button>
            </div>

            <div class="grid lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-2xl border overflow-hidden">
                    <h3 class="px-4 py-3 font-black border-b">سجل العمليات</h3>
                    <table class="w-full">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الوقت</th><th class="px-3 py-2">النوع</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2">الحجم</th><th class="px-3 py-2 text-start">تفاصيل</th></tr></thead>
                        <tbody>
                            <tr v-for="r in runs" :key="r.id" class="border-t">
                                <td class="px-3 py-2 font-mono">{{ time(r.created_at) }}</td>
                                <td class="px-3 py-2 text-center">{{ r.type === 'verify' ? 'اختبار استعادة' : 'نسخ' }}</td>
                                <td class="px-3 py-2 text-center font-bold" :class="r.status === 'ok' ? 'text-emerald-700' : 'text-rose-600'">{{ r.status === 'ok' ? 'ناجح' : 'فشل' }}</td>
                                <td class="px-3 py-2 text-center font-mono">{{ mb(r.size) }}</td>
                                <td class="px-3 py-2 text-slate-500 break-all">{{ r.error || (r.details ? `${r.details.tables} جدول · ${r.details.rows} سجل${r.details.method ? ' · ' + r.details.method : ''}` : '') }}</td>
                            </tr>
                            <tr v-if="!runs.length"><td colspan="5" class="px-3 py-6 text-center text-slate-400">لا توجد عمليات بعد</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="bg-white rounded-2xl border overflow-hidden">
                    <h3 class="px-4 py-3 font-black border-b">الملفات المحفوظة ({{ files.length }})</h3>
                    <ul class="divide-y">
                        <li v-for="f in files" :key="f.file" class="px-4 py-2 flex justify-between"><span class="font-mono" dir="ltr">{{ f.file }}</span><span class="font-mono text-slate-500">{{ mb(f.size) }}</span></li>
                        <li v-if="!files.length" class="px-4 py-6 text-center text-slate-400">لا توجد ملفات</li>
                    </ul>
                </div>
            </div>
        </div>
    </SuperAdminLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, monthLabel } from '@/utils/format';

const props = defineProps({ asset: Object, monthlyAmount: Number, bankAccounts: Array });
const disposeOpen = ref(false);
const form = useForm({ date: new Date().toISOString().substring(0, 10), proceeds: 0, method: 'bank_transfer', bank_account_id: '' });
const dispose = () => form.post(route('accounting.fixed-assets.dispose', props.asset.id), { onSuccess: () => (disposeOpen.value = false) });
</script>

<template>
    <AppLayout>
        <Head :title="asset.name_ar" />
        <PageHeader :title="`${asset.code} - ${asset.name_ar}`" :subtitle="asset.category?.name_ar" :crumbs="[{ label: 'الأصول الثابتة', href: route('accounting.fixed-assets.index') }, { label: asset.code }]">
            <template #actions>
                <button v-if="asset.status !== 'disposed' && $can('accounting.fixed_assets.delete')" @click="disposeOpen = true" class="px-4 py-2 rounded-xl border border-rose-300 text-rose-700 text-xs font-bold">بيع / استبعاد</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="التكلفة" :value="money(asset.cost)" />
            <StatCard label="مجمع الإهلاك" :value="money(asset.accumulated_depreciation)" tone="amber" />
            <StatCard label="القيمة الدفترية" :value="money(asset.book_value)" tone="emerald" />
            <StatCard label="قسط الإهلاك الشهري" :value="money(monthlyAmount)" tone="indigo" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs space-y-2">
                <p><span class="text-slate-500">تاريخ الشراء:</span> <b class="font-mono">{{ date(asset.acquisition_date) }}</b></p>
                <p><span class="text-slate-500">بداية الإهلاك:</span> <b class="font-mono">{{ date(asset.depreciation_start_date) }}</b></p>
                <p><span class="text-slate-500">العمر الإنتاجي:</span> <b>{{ asset.useful_life_months }} شهر</b></p>
                <p><span class="text-slate-500">الطريقة:</span> <b>{{ asset.depreciation_method === 'straight_line' ? 'القسط الثابت' : 'القسط المتناقص' }}</b></p>
                <p><span class="text-slate-500">القيمة التخريدية:</span> <b class="font-mono">{{ money(asset.salvage_value) }}</b></p>
                <p><span class="text-slate-500">الرقم التسلسلي:</span> <b>{{ asset.serial_number || '—' }}</b></p>
                <p><span class="text-slate-500">الموقع:</span> <b>{{ asset.location || '—' }}</b></p>
                <p v-if="asset.status === 'disposed'" class="text-rose-600 font-bold">تم الاستبعاد بتاريخ {{ date(asset.disposal_date) }} بمبلغ {{ money(asset.disposal_amount) }}</p>
            </div>
            <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">الشهر</th><th class="text-end px-4 py-2">قسط الإهلاك</th></tr></thead>
                    <tbody>
                        <tr v-for="d in asset.depreciations" :key="d.id" class="border-t border-slate-100"><td class="px-4 py-2 font-mono">{{ monthLabel(d.period) }}</td><td class="px-4 py-2 text-end font-mono">{{ money(d.amount) }}</td></tr>
                        <tr v-if="!asset.depreciations.length"><td colspan="2" class="py-8 text-center text-slate-400">لم يتم ترحيل إهلاك بعد</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog :show="disposeOpen" title="بيع / استبعاد الأصل" @close="disposeOpen = false">
            <div class="space-y-3 text-xs">
                <Field label="التاريخ" :error="form.errors.date"><input v-model="form.date" type="date" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مبلغ البيع (0 للإتلاف)" :error="form.errors.proceeds"><input v-model="form.proceeds" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="طريقة القبض"><select v-model="form.method" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">بنك</option><option value="cash">نقداً</option></select></Field>
                <Field label="الصندوق / البنك"><select v-model="form.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <p class="text-slate-500">سيتم احتساب الربح/الخسارة = مبلغ البيع − القيمة الدفترية ({{ money(asset.book_value) }}).</p>
            </div>
            <template #footer><button @click="dispose" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">تأكيد الاستبعاد</button></template>
        </Dialog>
    </AppLayout>
</template>

<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import { computed, ref, reactive } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ assets: Object, categories: Array, accounts: Array, branches: Array, costCenters: Array, bankAccounts: Array, totals: Object, filters: Object });

const assetOpen = ref(false);
const categoryOpen = ref(false);
const depreciateOpen = ref(false);

const today = new Date().toISOString().substring(0, 10);
const assetForm = useForm({ asset_category_id: '', code: '', name_ar: '', serial_number: '', location: '', branch_id: '', cost_center_id: '', acquisition_date: today, cost: '', salvage_value: 0, useful_life_months: '', total_units: '', unit_label: 'ساعة', paid_from: 'bank_transfer', bank_account_id: '', supplier_id: '', taxable: false, opening_accumulated: 0, notes: '' });
const categoryForm = useForm({ name_ar: '', useful_life_months: 60, depreciation_method: 'straight_line', declining_rate: '', asset_account_id: '', accumulated_account_id: '', expense_account_id: '' });
// Units-of-production categories need the asset's lifetime units.
const unitsCategory = computed(() => props.categories.find((c) => c.id === assetForm.asset_category_id)?.depreciation_method === 'units_of_production');
const depreciateForm = useForm({ month: new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1).toISOString().substring(0, 7) });

const filters = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
const apply = () => router.get(route('accounting.fixed-assets.index'), filters, { preserveState: true });

const saveAsset = () => assetForm.transform((d) => ({
    ...d,
    acquisition_vat: d.taxable && d.paid_from !== 'opening' ? Math.round((parseFloat(d.cost) || 0) * 15) / 100 : 0,
    opening_accumulated: d.paid_from === 'opening' ? d.opening_accumulated || 0 : 0,
})).post(route('accounting.fixed-assets.store'), { preserveScroll: true, onSuccess: () => { assetOpen.value = false; assetForm.reset(); } });
const saveCategory = () => categoryForm.post(route('accounting.fixed-assets.categories.store'), { preserveScroll: true, onSuccess: () => { categoryOpen.value = false; categoryForm.reset(); } });
const runDepreciation = () => depreciateForm.post(route('accounting.fixed-assets.depreciate'), { preserveScroll: true, onSuccess: () => (depreciateOpen.value = false) });

const statusLabel = { active: 'نشط', fully_depreciated: 'مهلك بالكامل', disposed: 'مستبعد' };
</script>

<template>
    <AppLayout>
        <Head title="الأصول الثابتة" />
        <PageHeader title="الأصول الثابتة" :crumbs="[{ label: 'المحاسبة' }, { label: 'الأصول الثابتة' }]">
            <template #actions>
                <ImportButton v-if="$can('accounting.fixed_assets.create')" type="fixed_assets" title="استيراد الأصول الثابتة من Excel" hint="تُسجّل كأصول افتتاحية بتاريخ اليوم: التكلفة والمجمع السابق (opening_accumulated) مقابل الأرصدة الافتتاحية. الفئة باسمها كما في النظام." />
                <button v-if="$can('accounting.fixed_assets.post')" @click="depreciateOpen = true" class="px-3 py-2 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">ترحيل الإهلاك الشهري</button>
                <button v-if="$can('accounting.fixed_assets.edit')" @click="categoryOpen = true" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">فئة أصول</button>
                <button v-if="$can('accounting.fixed_assets.create')" @click="assetOpen = true" :disabled="!categories.length" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40"><PlusIcon class="w-4 h-4" /> أصل جديد</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-3 gap-3 mb-4">
            <StatCard label="تكلفة الأصول" :value="money(totals.cost)" />
            <StatCard label="مجمع الإهلاك" :value="money(totals.accumulated)" tone="amber" />
            <StatCard label="صافي القيمة الدفترية" :value="money(totals.book_value)" tone="emerald" />
        </div>

        <form @submit.prevent="apply" class="flex flex-wrap gap-2 mb-3 text-xs">
            <input v-model="filters.search" placeholder="بحث بالاسم / الرمز / الرقم التسلسلي" class="rounded-xl border-slate-200 text-xs w-64" />
            <select v-model="filters.status" class="rounded-xl border-slate-200 text-xs"><option value="">كل الحالات</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select>
            <button class="px-4 rounded-xl bg-slate-800 text-white font-bold">{{ $t('ui.apply') }}</button>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="text-start px-3 py-2">{{ $t('ui.code') }}</th><th class="text-start px-3 py-2">الأصل</th><th class="text-start px-3 py-2">الفئة</th>
                        <th class="px-3 py-2">تاريخ الشراء</th><th class="text-end px-3 py-2">التكلفة</th><th class="text-end px-3 py-2">مجمع الإهلاك</th><th class="text-end px-3 py-2">القيمة الدفترية</th><th class="px-3 py-2">{{ $t('ui.status') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in assets.data" :key="a.id" class="border-t border-slate-100 hover:bg-slate-50/60">
                            <td class="px-3 py-2 font-mono"><Link :href="route('accounting.fixed-assets.show', a.id)" class="text-indigo-600">{{ a.code }}</Link></td>
                            <td class="px-3 py-2 font-semibold">{{ a.name_ar }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ a.category?.name_ar }}</td>
                            <td class="px-3 py-2 text-center font-mono">{{ date(a.acquisition_date) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(a.cost) }}</td>
                            <td class="px-3 py-2 text-end font-mono">{{ money(Number(a.accumulated_depreciation) + Number(a.accumulated_impairment || 0)) }}</td>
                            <td class="px-3 py-2 text-end font-mono font-bold">{{ money(a.book_value) }}</td>
                            <td class="px-3 py-2 text-center">{{ statusLabel[a.status] }}</td>
                        </tr>
                        <tr v-if="!assets.data.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ categories.length ? 'لا توجد أصول' : 'ابدأ بإضافة فئة أصول (مثل: أجهزة حاسب، سيارات)' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="assets" />
        </div>

        <Dialog :show="assetOpen" title="تسجيل أصل ثابت" size="lg" @close="assetOpen = false">
            <form @submit.prevent="saveAsset" class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                <Field label="الفئة" :error="assetForm.errors.asset_category_id"><select v-model="assetForm.asset_category_id" required class="w-full rounded-xl border-slate-200 text-xs"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></Field>
                <Field label="رمز الأصل" :error="assetForm.errors.code"><input v-model="assetForm.code" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="اسم الأصل" :error="assetForm.errors.name_ar"><input v-model="assetForm.name_ar" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الرقم التسلسلي"><input v-model="assetForm.serial_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="الموقع"><input v-model="assetForm.location" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="مركز التكلفة"><select v-model="assetForm.cost_center_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></Field>
                <Field label="تاريخ الشراء" :error="assetForm.errors.acquisition_date"><input v-model="assetForm.acquisition_date" type="date" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="التكلفة" :error="assetForm.errors.cost"><input v-model="assetForm.cost" type="number" step="0.01" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="القيمة التخريدية" :error="assetForm.errors.salvage_value"><input v-model="assetForm.salvage_value" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="العمر الإنتاجي (شهر)" hint="فارغ = حسب الفئة"><input v-model="assetForm.useful_life_months" type="number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <template v-if="unitsCategory">
                    <Field label="إجمالي الوحدات المتوقعة طوال العمر" :error="assetForm.errors.total_units"><input v-model="assetForm.total_units" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="وحدة القياس"><input v-model="assetForm.unit_label" placeholder="ساعة، كم، قطعة" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </template>
                <Field label="طريقة السداد"><select v-model="assetForm.paid_from" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">بنك</option><option value="cash">نقداً</option><option value="payables">آجل لمورد</option><option value="opening">رصيد افتتاحي</option></select></Field>
                <Field v-if="['cash','bank_transfer'].includes(assetForm.paid_from)" label="الصندوق / البنك"><select v-model="assetForm.bank_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <Field v-if="assetForm.paid_from === 'payables'" label="رقم المورد" :error="assetForm.errors.supplier_id"><input v-model="assetForm.supplier_id" type="number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="assetForm.paid_from !== 'opening'" label="ضريبة المدخلات" :error="assetForm.errors.acquisition_vat">
                    <label class="flex items-center gap-2 py-2"><input v-model="assetForm.taxable" type="checkbox" class="rounded" /> فاتورة ضريبية {{ $taxRate() }}% ({{ ((parseFloat(assetForm.cost) || 0) * $taxRate() / 100).toFixed(2) }})</label>
                </Field>
                <Field v-else label="مجمع الإهلاك حتى تاريخ البدء" :error="assetForm.errors.opening_accumulated"><input v-model="assetForm.opening_accumulated" type="number" step="0.01" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </form>
            <template #footer>
                <button @click="assetOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('ui.cancel') }}</button>
                <button @click="saveAsset" :disabled="assetForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </Dialog>

        <Dialog :show="categoryOpen" title="فئة أصول جديدة" @close="categoryOpen = false">
            <form @submit.prevent="saveCategory" class="space-y-3 text-xs">
                <Field label="اسم الفئة" :error="categoryForm.errors.name_ar"><input v-model="categoryForm.name_ar" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="العمر الإنتاجي (شهر)"><input v-model="categoryForm.useful_life_months" type="number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="طريقة الإهلاك"><select v-model="categoryForm.depreciation_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="straight_line">القسط الثابت</option><option value="declining_balance">القسط المتناقص</option><option value="units_of_production">وحدات الإنتاج (ساعات/كم/وحدات)</option></select></Field>
                </div>
                <Field label="حساب الأصل" hint="فارغ = الحساب الافتراضي"><select v-model="categoryForm.asset_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="a in accounts.filter(x => x.type === 'asset')" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option></select></Field>
                <Field label="حساب مجمع الإهلاك"><select v-model="categoryForm.accumulated_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="a in accounts.filter(x => x.type === 'asset')" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option></select></Field>
                <Field label="حساب مصروف الإهلاك"><select v-model="categoryForm.expense_account_id" class="w-full rounded-xl border-slate-200 text-xs"><option value="">الافتراضي</option><option v-for="a in accounts.filter(x => x.type === 'expense')" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name_ar }}</option></select></Field>
            </form>
            <template #footer>
                <button @click="saveCategory" :disabled="categoryForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </Dialog>

        <Dialog :show="depreciateOpen" title="ترحيل الإهلاك الشهري" size="sm" @close="depreciateOpen = false">
            <Field label="الشهر" hint="يتم ترحيل إهلاك كل الأصول النشطة لهذا الشهر (لا يتكرر لنفس الشهر)"><input v-model="depreciateForm.month" type="month" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <template #footer>
                <button @click="runDepreciation" :disabled="depreciateForm.processing" class="px-4 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold">ترحيل</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

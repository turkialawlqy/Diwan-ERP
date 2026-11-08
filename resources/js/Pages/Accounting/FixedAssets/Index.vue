<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import ImportButton from '@/Components/UI/ImportButton.vue';
import { computed, ref, reactive } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { money, date, today, thisMonth, addMonths } from '@/utils/format';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({ assets: Object, categories: Array, accounts: Array, branches: Array, costCenters: Array, bankAccounts: Array, totals: Object, filters: Object });

const assetOpen = ref(false);
const categoryOpen = ref(false);
const depreciateOpen = ref(false);

const assetForm = useForm({ asset_category_id: '', code: '', name_ar: '', serial_number: '', location: '', branch_id: '', cost_center_id: '', acquisition_date: today(), cost: '', salvage_value: 0, useful_life_months: '', total_units: '', unit_label: 'ساعة', paid_from: 'bank_transfer', bank_account_id: '', supplier_id: '', taxable: false, opening_accumulated: 0, notes: '' });
const categoryForm = useForm({ name_ar: '', useful_life_months: 60, depreciation_method: 'straight_line', declining_rate: '', asset_account_id: '', accumulated_account_id: '', expense_account_id: '' });
// Units-of-production categories need the asset's lifetime units.
const unitsCategory = computed(() => props.categories.find((c) => c.id === assetForm.asset_category_id)?.depreciation_method === 'units_of_production');
const depreciateForm = useForm({ month: addMonths(thisMonth(), -1) });

const filters = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
const apply = () => router.get(route('accounting.fixed-assets.index'), filters, { preserveState: true });

const saveAsset = () => assetForm.transform((d) => ({
    ...d,
    acquisition_vat: d.taxable && d.paid_from !== 'opening' ? Math.round((parseFloat(d.cost) || 0) * 15) / 100 : 0,
    opening_accumulated: d.paid_from === 'opening' ? d.opening_accumulated || 0 : 0,
})).post(route('accounting.fixed-assets.store'), { preserveScroll: true, onSuccess: () => { assetOpen.value = false; assetForm.reset(); } });
const saveCategory = () => categoryForm.post(route('accounting.fixed-assets.categories.store'), { preserveScroll: true, onSuccess: () => { categoryOpen.value = false; categoryForm.reset(); } });
const runDepreciation = () => depreciateForm.post(route('accounting.fixed-assets.depreciate'), { preserveScroll: true, onSuccess: () => (depreciateOpen.value = false) });

const statusLabel = computed(() => ({ active: t('fassets.st_active'), fully_depreciated: t('fassets.st_fully'), disposed: t('fassets.st_disposed') }));
</script>

<template>
    <AppLayout>
        <Head :title="$t('fassets.fixed_assets')" />
        <PageHeader :title="$t('fassets.fixed_assets')" :crumbs="[{ label: $t('fassets.accounting') }, { label: $t('fassets.fixed_assets') }]">
            <template #actions>

                <button v-if="$can('accounting.fixed_assets.post')" @click="depreciateOpen = true" class="px-3 py-2 rounded-xl border border-amber-300 text-amber-700 text-xs font-bold">{{ $t('fassets.post_monthly_depreciation') }}</button>
                <button v-if="$can('accounting.fixed_assets.edit')" @click="categoryOpen = true" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">{{ $t('fassets.asset_category') }}</button>
                <button v-if="$can('accounting.fixed_assets.create')" @click="assetOpen = true" :disabled="!categories.length" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-40"><PlusIcon class="w-4 h-4" /> {{ $t('fassets.new_asset') }}</button>
            <ImportButton v-if="$can('accounting.fixed_assets.create')" type="fixed_assets" :title="$t('fassets.import_fixed_assets_from_excel')" :hint="$t('fassets.recorded_as_opening_assets_dated')" />
</template>
        </PageHeader>

        <div class="grid grid-cols-3 gap-3 mb-4">
            <StatCard :label="$t('fassets.assets_cost')" :value="money(totals.cost)" />
            <StatCard :label="$t('fassets.accumulated_depreciation')" :value="money(totals.accumulated)" tone="amber" />
            <StatCard :label="$t('fassets.net_book_value')" :value="money(totals.book_value)" tone="emerald" />
        </div>

        <form @submit.prevent="apply" class="flex flex-wrap gap-2 mb-3 text-xs">
            <input v-model="filters.search" :placeholder="$t('fassets.search_by_name_code_serial_numbe')" class="rounded-xl border-slate-200 text-xs w-64" />
            <select v-model="filters.status" class="rounded-xl border-slate-200 text-xs"><option value="">{{ $t('fassets.all_statuses') }}</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select>
            <button class="px-4 rounded-xl bg-slate-800 text-white font-bold">{{ $t('ui.apply') }}</button>
        </form>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="text-start px-3 py-2">{{ $t('ui.code') }}</th><th class="text-start px-3 py-2">{{ $t('fassets.asset') }}</th><th class="text-start px-3 py-2">{{ $t('fassets.category') }}</th>
                        <th class="px-3 py-2">{{ $t('fassets.purchase_date') }}</th><th class="text-end px-3 py-2">{{ $t('fassets.cost') }}</th><th class="text-end px-3 py-2">{{ $t('fassets.accumulated_depreciation') }}</th><th class="text-end px-3 py-2">{{ $t('fassets.book_value') }}</th><th class="px-3 py-2">{{ $t('ui.status') }}</th>
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
                        <tr v-if="!assets.data.length"><td colspan="8" class="py-10 text-center text-slate-400">{{ categories.length ? $t('fassets.no_assets') : $t('fassets.add_category_first') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="assets" />
        </div>

        <Dialog :show="assetOpen" :title="$t('fassets.register_a_fixed_asset')" size="lg" @close="assetOpen = false">
            <form @submit.prevent="saveAsset" class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                <Field :label="$t('fassets.category')" :error="assetForm.errors.asset_category_id"><SelectCreate v-model="assetForm.asset_category_id" :options="categories" entity="category" label="name_ar" :allow-empty="false" required /></Field>
                <Field :label="$t('fassets.asset_code')" :error="assetForm.errors.code"><input v-model="assetForm.code" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.asset_name')" :error="assetForm.errors.name_ar"><input v-model="assetForm.name_ar" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.serial_number')"><input v-model="assetForm.serial_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.location')"><input v-model="assetForm.location" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.cost_center')"><SelectCreate v-model="assetForm.cost_center_id" :options="costCenters" entity="cost_center" label="name_ar" placeholder="—" /></Field>
                <Field :label="$t('fassets.purchase_date')" :error="assetForm.errors.acquisition_date"><DatePicker v-model="assetForm.acquisition_date" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.cost')" :error="assetForm.errors.cost"><input v-model.number="assetForm.cost" type="text" inputmode="decimal" data-num step="0.01" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.salvage_value')" :error="assetForm.errors.salvage_value"><input v-model.number="assetForm.salvage_value" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('fassets.useful_life_months')" :hint="$t('fassets.empty_by_category')"><input v-model.number="assetForm.useful_life_months" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <template v-if="unitsCategory">
                    <Field :label="$t('fassets.total_expected_units_over_the_li')" :error="assetForm.errors.total_units"><input v-model.number="assetForm.total_units" type="text" inputmode="decimal" data-num step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('fassets.unit_of_measure')"><input v-model="assetForm.unit_label" :placeholder="$t('fassets.hour_km_piece')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                </template>
                <Field :label="$t('fassets.payment_method')"><select v-model="assetForm.paid_from" class="w-full rounded-xl border-slate-200 text-xs"><option value="bank_transfer">{{ $t('fassets.bank') }}</option><option value="cash">{{ $t('fassets.cash') }}</option><option value="payables">{{ $t('fassets.on_credit_from_a_supplier') }}</option><option value="opening">{{ $t('fassets.opening_balance') }}</option></select></Field>
                <Field v-if="['cash','bank_transfer'].includes(assetForm.paid_from)" :label="$t('fassets.cash_box_bank')"><SelectCreate v-model="assetForm.bank_account_id" :options="bankAccounts" entity="bank" label="name" :placeholder="$t('fassets.default')" /></Field>
                <Field v-if="assetForm.paid_from === 'payables'" :label="$t('fassets.supplier_number')" :error="assetForm.errors.supplier_id"><input v-model.number="assetForm.supplier_id" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field v-if="assetForm.paid_from !== 'opening'" :label="$t('fassets.input_tax')" :error="assetForm.errors.acquisition_vat">
                    <label class="flex items-center gap-2 py-2"><input v-model="assetForm.taxable" type="checkbox" class="rounded" /> {{ $t('fassets.taxable_invoice', { rate: $taxRate(), amount: ((parseFloat(assetForm.cost) || 0) * $taxRate() / 100).toFixed(2) }) }}</label>
                </Field>
                <Field v-else :label="$t('fassets.accumulated_depreciation_up_to_t')" :error="assetForm.errors.opening_accumulated"><input v-model.number="assetForm.opening_accumulated" type="text" inputmode="decimal" data-num step="0.01" min="0" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </form>
            <template #footer>
                <button @click="assetOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('ui.cancel') }}</button>
                <button @click="saveAsset" :disabled="assetForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </Dialog>

        <Dialog :show="categoryOpen" :title="$t('fassets.new_asset_category')" @close="categoryOpen = false">
            <form @submit.prevent="saveCategory" class="space-y-3 text-xs">
                <Field :label="$t('fassets.category_name')" :error="categoryForm.errors.name_ar"><input v-model="categoryForm.name_ar" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <div class="grid grid-cols-2 gap-3">
                    <Field :label="$t('fassets.useful_life_months')"><input v-model.number="categoryForm.useful_life_months" type="text" inputmode="decimal" data-num class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('fassets.depreciation_method')"><select v-model="categoryForm.depreciation_method" class="w-full rounded-xl border-slate-200 text-xs"><option value="straight_line">{{ $t('fassets.straight_line') }}</option><option value="declining_balance">{{ $t('fassets.declining_balance') }}</option><option value="units_of_production">{{ $t('fassets.units_of_production_hours_km_uni') }}</option></select></Field>
                </div>
                <Field :label="$t('fassets.asset_account')" :hint="$t('fassets.empty_the_default_account')"><SelectCreate v-model="categoryForm.asset_account_id" :options="accounts.filter(x => x.type === 'asset')" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" :placeholder="$t('fassets.default')" /></Field>
                <Field :label="$t('fassets.accumulated_depreciation_account')"><SelectCreate v-model="categoryForm.accumulated_account_id" :options="accounts.filter(x => x.type === 'asset')" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" :placeholder="$t('fassets.default')" /></Field>
                <Field :label="$t('fassets.depreciation_expense_account')"><SelectCreate v-model="categoryForm.expense_account_id" :options="accounts.filter(x => x.type === 'expense')" entity="account" :label="(a) => `${a.code} - ${a.name_ar}`" :placeholder="$t('fassets.default')" /></Field>
            </form>
            <template #footer>
                <button @click="saveCategory" :disabled="categoryForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.save') }}</button>
            </template>
        </Dialog>

        <Dialog :show="depreciateOpen" :title="$t('fassets.post_monthly_depreciation')" size="sm" @close="depreciateOpen = false">
            <Field :label="$t('fassets.month')" :hint="$t('fassets.depreciation_of_all_active_asset')"><DatePicker v-model="depreciateForm.month" type="month" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <template #footer>
                <button @click="runDepreciation" :disabled="depreciateForm.processing" class="px-4 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold">{{ $t('fassets.post') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

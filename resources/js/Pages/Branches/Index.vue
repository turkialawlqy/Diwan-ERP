<script setup>
import { useI18n } from 'vue-i18n';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Dialog from '@/Components/UI/Dialog.vue';
import Field from '@/Components/UI/Field.vue';
import { confirmAction } from '@/composables/useDialog';
import { PlusIcon, PencilSquareIcon, TrashIcon, GlobeAltIcon } from '@heroicons/vue/24/outline';
import { today } from '@/utils/format';
const { t } = useI18n();

/**
 * Branches and the country each one operates in: the country sets the tax
 * (rates, registration number, e-invoicing system) and the default currency.
 */
const props = defineProps({ branches: Array, stats: Object, countries: Array, companyCountry: String, baseCurrency: String, rateHistory: { type: Object, default: () => ({}) }, activityOptions: { type: Object, default: () => ({}) } });

const open = ref(false);
const editing = ref(null);
const blank = () => ({ name_ar: '', name_en: '', code: '', city: '', phone: '', address: '', is_main: false, country_code: props.companyCountry, currency: '', tax_number: '', tax_registered: true, commercial_registration: '', all_activities: true, modules: [] });
const form = useForm(blank());

const country = computed(() => props.countries.find((c) => c.code === form.country_code) || props.countries[0]);
// The company's own country keeps its base currency; another country suggests its own.
const suggestedCurrency = computed(() => (form.country_code === props.companyCountry ? props.baseCurrency : country.value.currency));
watch(() => form.country_code, () => { if (!editing.value || !form.currency) form.currency = ''; });

const add = () => { editing.value = null; form.defaults(blank()); form.reset(); form.clearErrors(); open.value = true; };
const edit = (b) => {
    editing.value = b;
    form.defaults({ name_ar: b.name_ar, name_en: b.name_en || '', code: b.code || '', city: b.city || '', phone: b.phone || '', address: b.address || '', is_main: !!b.is_main,
        country_code: b.profile.country, currency: b.currency || '', tax_number: b.tax_number || '', tax_registered: b.tax_registered !== false, commercial_registration: b.commercial_registration || '', all_activities: b.modules == null, modules: b.modules || [] });
    form.reset(); form.clearErrors(); open.value = true;
};
const save = () => {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    form.transform((d) => { const { all_activities, ...rest } = d; return { ...rest, modules: all_activities ? null : d.modules }; });
    editing.value ? form.put(route('branches.update', editing.value.id), options) : form.post(route('branches.store'), options);
};
const remove = async (b) => {
    if (await confirmAction(t('x_branches_index.delete_the_branch_n', { n0: b.name_ar }))) router.delete(route('branches.destroy', b.id), { preserveScroll: true });
};
const toggleModule = (k) => { form.modules = form.modules.includes(k) ? form.modules.filter((x) => x !== k) : [...form.modules, k]; };
const rates = (p) => (p.has_tax ? p.rates.map((r) => `${Number(r)}%`).join(' / ') : t('x_branches_index.no_tax'));

// Countries the company works in (with tax), and a rate change from a date.
const taxCountries = computed(() => [...new Map(props.branches.filter((b) => b.profile.has_tax).map((b) => [b.profile.country, b.profile])).values()]);
const rateForm = useForm({ country: '', standard: '', rates: '', from: today() });
const rateOpen = ref(false);
const editRates = (p) => { rateForm.country = p.country; rateForm.standard = p.rate; rateForm.rates = p.rates.filter((r) => r !== p.rate).join(', '); rateForm.clearErrors(); rateOpen.value = true; };
const saveRates = () => rateForm.post(route('branches.tax-rates'), { preserveScroll: true, onSuccess: () => { rateOpen.value = false; } });
</script>

<template>
    <AppLayout own-errors>
        <Head :title="$t('x_branches_index.branches')" />
        <PageHeader :title="$t('x_branches_index.branches')" :subtitle="$t('x_branches_index.each_branch_is_in_a_country_its')" :crumbs="[{ label: $t('x_branches_index.settings') }, { label: $t('x_branches_index.branches') }]">
            <template #actions>
                <button v-if="$can('admin.branches.create')" @click="add" class="inline-flex items-center gap-1 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold"><PlusIcon class="w-4 h-4" /> {{ $t('x_branches_index.new_branch') }}</button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
            <StatCard :label="$t('x_branches_index.branches')" :value="stats.total_branches" />
            <StatCard :label="$t('x_branches_index.active_branches')" :value="stats.active_branches" />
            <StatCard :label="$t('x_branches_index.countries')" :value="stats.countries" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4" data-branches>
            <div v-for="b in branches" :key="b.id" class="bg-white border border-slate-200 rounded-2xl p-5 text-xs">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-black text-sm text-slate-800">{{ b.name_ar }} <span v-if="b.is_main" class="ms-1 px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-3xs">{{ $t('x_branches_index.main') }}</span></p>
                        <p class="text-slate-500 mt-0.5"><span class="font-mono">{{ b.code }}</span><span v-if="b.city"> · {{ b.city }}</span><span v-if="b.phone"> · <span dir="ltr">{{ b.phone }}</span></span></p>
                    </div>
                    <div class="flex gap-1">
                        <button v-if="$can('admin.branches.edit')" @click="edit(b)" class="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600" :title="$t('x_branches_index.edit')"><PencilSquareIcon class="w-4 h-4" /></button>
                        <button v-if="!b.is_main && $can('admin.branches.delete')" @click="remove(b)" class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600" :title="$t('x_branches_index.delete')"><TrashIcon class="w-4 h-4" /></button>
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5" data-branch-profile>
                    <dt class="text-slate-500">{{ $t('x_branches_index.country') }}</dt><dd class="font-bold"><GlobeAltIcon class="w-3.5 h-3.5 inline text-slate-400" /> {{ b.profile.country_name }}</dd>
                    <dt class="text-slate-500">{{ $t('x_branches_index.currency') }}</dt><dd class="font-bold font-mono">{{ b.profile.currency }}</dd>
                    <dt class="text-slate-500">{{ b.profile.has_tax ? b.profile.name : $t('x_branches_index.tax_2') }}</dt><dd class="font-bold">{{ rates(b.profile) }}</dd>
                    <dt class="text-slate-500">{{ b.profile.tax_number_label }}</dt><dd class="font-mono">{{ b.profile.seller_tax_number || '—' }}</dd>
                    <dt class="text-slate-500">{{ $t('x_branches_index.e_invoicing') }}</dt><dd>{{ b.profile.einvoicing_label }}</dd>
                    <dt class="text-slate-500">{{ $t('x_branches_index.users') }}</dt><dd>{{ b.users_count }}</dd>
                </dl>
            </div>
        </div>

        <div v-if="taxCountries.length" class="mt-6 bg-white border border-slate-200 rounded-2xl p-5 text-xs" data-tax-rates>
            <h3 class="font-black text-sm text-slate-800">{{ $t('x_branches_index.tax_rates_by_country') }}</h3>
            <p class="text-slate-500 mt-1">{{ $t('x_branches_index.the_default_rates_come_from_each') }}</p>
            <div class="mt-3 divide-y divide-slate-100">
                <div v-for="p in taxCountries" :key="p.country" class="py-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-bold">{{ p.country_name }} — {{ p.name }}: <span class="font-mono">{{ rates(p) }}</span></p>
                        <p v-for="h in rateHistory[p.country] || []" :key="h.from" class="text-slate-500">{{ $t('x_branches_index.from_n_n', { n0: h.from, n1: h.standard }) }}<span v-if="h.rates.length"> {{ $t('x_branches_index.and_n', { n0: h.rates.join('، ') }) }}</span></p>
                    </div>
                    <button v-if="$can('admin.branches.edit')" @click="editRates(p)" class="px-3 py-1.5 rounded-lg border border-slate-200 font-bold">{{ $t('x_branches_index.edit_the_rates') }}</button>
                </div>
            </div>
        </div>

        <Dialog :show="rateOpen" :title="$t('x_branches_index.edit_tax_rates')" @close="rateOpen = false">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <Field :label="$t('x_branches_index.standard_rate')" :error="rateForm.errors.standard"><input v-model.number="rateForm.standard" type="text" inputmode="decimal" data-num step="0.01" min="0" max="100" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('x_branches_index.reduced_rates_optional')" :error="rateForm.errors.rates" :hint="$t('x_branches_index.e_g_5_10')"><input v-model="rateForm.rates" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('x_branches_index.effective_from')" :error="rateForm.errors.from"><DatePicker v-model="rateForm.from" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
            <template #footer>
                <button type="button" @click="saveRates" :disabled="rateForm.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('x_branches_index.save') }}</button>
                <button type="button" @click="rateOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('x_branches_index.cancel') }}</button>
            </template>
        </Dialog>

        <Dialog :show="open" :title="editing ? $t('x_branches_index.edit_the_branch_n', { n0: editing.name_ar }) : $t('x_branches_index.new_branch')" size="lg" @close="open = false">
            <form @submit.prevent="save" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs" data-branch-form>
                <Field :label="$t('x_branches_index.branch_name')" :error="form.errors.name_ar"><input v-model="form.name_ar" required class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_branches_index.name_in_english')" :error="form.errors.name_en"><input v-model="form.name_en" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_branches_index.branch_code')" :error="form.errors.code"><input v-model="form.code" required class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <Field :label="$t('x_branches_index.city')" :error="form.errors.city"><input v-model="form.city" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_branches_index.phone')" :error="form.errors.phone"><input v-model="form.phone" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('x_branches_index.title')" :error="form.errors.address"><input v-model="form.address" class="w-full rounded-xl border-slate-200 text-xs" /></Field>

                <div class="sm:col-span-2 mt-1 pt-3 border-t border-slate-100 font-black text-slate-700">{{ $t('x_branches_index.country_and_tax') }}</div>
                <Field :label="$t('x_branches_index.country_2')" :error="form.errors.country_code" :hint="editing?.locked_country ? $t('x_branches_index.the_country_of_a_branch_that_iss') : ''">
                    <select v-model="form.country_code" :disabled="editing?.locked_country" class="w-full rounded-xl border-slate-200 text-xs" data-country>
                        <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                    </select>
                </Field>
                <Field :label="$t('x_branches_index.branch_currency')" :error="form.errors.currency" :hint="$t('x_branches_index.default_n', { n0: suggestedCurrency })">
                    <input v-model="form.currency" maxlength="3" :placeholder="suggestedCurrency" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs font-mono uppercase" />
                </Field>
                <div class="sm:col-span-2 p-3 rounded-xl bg-slate-50 text-slate-600 space-y-0.5" data-country-summary>
                    <p><span class="font-bold">{{ $t('x_branches_index.tax') }}</span> {{ country.has_tax ? `${country.tax_name} — ${country.rates.map((r) => r + '%').join(' / ')}` : $t('x_branches_index.there_is_no_vat_invoices_are_iss') }}</p>
                    <p><span class="font-bold">{{ $t('x_branches_index.e_invoicing_2') }}</span> {{ country.einvoicing }}</p>
                </div>
                <Field :label="country.tax_number_label" :error="form.errors.tax_number" :hint="country.tax_number_example ? $t('x_branches_index.example_n_leave_empty_to_use_the', { n0: country.tax_number_example }) : ''">
                    <input v-model="form.tax_number" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs font-mono" data-tax-number />
                </Field>
                <Field :label="$t('x_branches_index.commercial_register')" :error="form.errors.commercial_registration"><input v-model="form.commercial_registration" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
                <label v-if="country.has_tax" class="sm:col-span-2 flex items-center gap-2 font-bold text-slate-700"><input v-model="form.tax_registered" type="checkbox" class="rounded" /> {{ $t('x_branches_index.the_branch_is_tax_registered_an') }}</label>
                <label class="sm:col-span-2 flex items-center gap-2 text-slate-700"><input v-model="form.is_main" type="checkbox" class="rounded" /> {{ $t('x_branches_index.main_branch') }}</label>
                <div v-if="Object.keys(activityOptions).length" class="sm:col-span-2 border-t border-slate-100 pt-3 space-y-2" data-branch-activities>
                    <p class="font-bold text-slate-700">{{ $t('x_branches_index.branch_activities') }}</p>
                    <label class="flex items-center gap-2"><input v-model="form.all_activities" type="checkbox" class="rounded" data-all-activities /> {{ $t('x_branches_index.carries_out_all_of_the_company_s') }}</label>
                    <div v-if="!form.all_activities" class="flex flex-wrap gap-2">
                        <label v-for="(label, key) in activityOptions" :key="key" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border" :class="form.modules.includes(key) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200'" :data-activity="key">
                            <input type="checkbox" class="rounded border-slate-300" :checked="form.modules.includes(key)" @change="toggleModule(key)" /> {{ label }}
                        </label>
                    </div>
                    <p class="text-3xs text-slate-500">{{ $t('x_branches_index.this_branch_s_users_do_not_see_o') }}</p>
                </div>
            </form>
            <template #footer>
                <button type="button" @click="save" :disabled="form.processing" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold disabled:opacity-50">{{ $t('x_branches_index.save') }}</button>
                <button type="button" @click="open = false" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ $t('x_branches_index.cancel') }}</button>
            </template>
        </Dialog>
    </AppLayout>
</template>

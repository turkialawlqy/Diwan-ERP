<script setup>
import DatePicker from '@/Components/UI/DatePicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import ProductPicker from '@/Components/Documents/ProductPicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { today } from '@/utils/format';

/**
 * «إدخال مواد للمستودع»: the warehouse keeper adds only items and quantities. The stock enters at once and a draft purchase
 * invoice opens for accounting to complete (supplier, date, prices). Batch / serial items ask for their batch or serials.
 */
const props = defineProps({ warehouses: Array, suggestedNumber: String });

const blank = () => ({ product: null, product_id: '', quantity: '', batch_number: '', expiry_date: '', serials_text: '' });
const form = useForm({
    warehouse_id: props.warehouses.find((w) => w.is_main)?.id ?? props.warehouses[0]?.id ?? '',
    receipt_date: today(),
    supplier_delivery_number: '',
    notes: '',
    lines: [blank()],
});
useUnsavedGuard(form);

const pick = (line, product) => {
    line.product = product;
    line.product_id = product?.id ?? '';
};
const serialsOf = (line) => String(line.serials_text || '').split(/[\n,;]+/).map((s) => s.trim()).filter(Boolean);
const add = () => form.lines.push(blank());
const remove = (i) => form.lines.length > 1 && form.lines.splice(i, 1);
const err = (i, f) => form.errors[`lines.${i}.${f}`];

const submit = () => form.transform((d) => ({
    ...d,
    lines: d.lines.filter((l) => l.product_id).map((l) => ({
        product_id: l.product_id,
        quantity: Number(l.quantity) || 0,
        batch_number: l.batch_number || null,
        expiry_date: l.expiry_date || null,
        serials: l.product?.tracking === 'serial' ? serialsOf(l) : [],
    })),
})).post(route('inventory.stock-in.store'));

const input = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <AppLayout>
        <Head :title="$t('stockin.new')" />
        <PageHeader :title="$t('stockin.new')" :subtitle="$t('stockin.subtitle')" :crumbs="[{ label: 'المخزون' }, { label: $t('stockin.title'), href: route('inventory.stock-in.index') }, { label: $t('stockin.new') }]" />

        <form @submit.prevent="submit" class="space-y-4 text-xs">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-4 gap-4">
                <Field :label="$t('stockin.warehouse')" :error="form.errors.warehouse_id" class="md:col-span-2">
                    <SelectCreate v-model="form.warehouse_id" :options="warehouses" label="name_ar" entity="warehouse" :allow-empty="false" required />
                </Field>
                <Field :label="$t('ui.date')" :error="form.errors.receipt_date"><DatePicker v-model="form.receipt_date" :max="today()" :class="input" /></Field>
                <Field :label="$t('stockin.delivery_number')" :error="form.errors.supplier_delivery_number"><input v-model="form.supplier_delivery_number" :class="input" /></Field>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" data-stockin-lines>
                <p v-if="form.errors.lines" class="rounded-xl bg-rose-50 border border-rose-200 p-3 font-bold text-rose-700">{{ form.errors.lines }}</p>
                <div v-for="(l, i) in form.lines" :key="i" class="grid grid-cols-12 gap-2 items-start border-b border-slate-100 pb-3" data-stockin-line>
                    <div class="col-span-12 md:col-span-6">
                        <ProductPicker :model-value="l.product_id" @select="(p) => pick(l, p)" />
                        <p v-if="err(i, 'product_id')" class="text-rose-600 mt-1">{{ err(i, 'product_id') }}</p>
                    </div>
                    <div class="col-span-6 md:col-span-2">
                        <input v-model="l.quantity" type="text" inputmode="decimal" data-num :placeholder="$t('stockin.quantity')" :class="input" class="font-mono" data-stockin-qty />
                        <p v-if="err(i, 'quantity')" class="text-rose-600 mt-1">{{ err(i, 'quantity') }}</p>
                    </div>
                    <div class="col-span-6 md:col-span-1 text-end"><button type="button" class="px-2 py-1 text-rose-500 font-bold" :aria-label="$t('ui.delete')" @click="remove(i)">×</button></div>
                    <template v-if="l.product?.tracking === 'batch'">
                        <div class="col-span-6 md:col-span-3"><input v-model="l.batch_number" :placeholder="$t('stockin.batch')" :class="input" /></div>
                        <div class="col-span-6 md:col-span-3"><DatePicker v-model="l.expiry_date" :class="input" /></div>
                    </template>
                    <div v-if="l.product?.tracking === 'serial'" class="col-span-12">
                        <textarea v-model="l.serials_text" rows="2" :placeholder="$t('stockin.serials')" :class="input" class="font-mono"></textarea>
                        <p class="text-slate-400 mt-0.5">{{ $t('stockin.serials_count', { n: serialsOf(l).length }) }}</p>
                    </div>
                </div>
                <button type="button" class="text-indigo-600 font-bold" data-stockin-add @click="add">+ {{ $t('stockin.add_line') }}</button>
            </div>

            <Field :label="$t('stockin.notes')" :error="form.errors.notes"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <p class="text-slate-500">{{ $t('stockin.note') }}</p>

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold" data-stockin-save>{{ $t('stockin.save') }}</button>
                <Link :href="route('inventory.stock-in.index')" class="px-5 py-2 rounded-xl border border-slate-200 font-bold text-slate-600">{{ $t('ui.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

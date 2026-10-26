<script setup>
import { confirmAction } from '@/composables/useDialog';
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import Field from '@/Components/UI/Field.vue';
import { qty, money, date } from '@/utils/format';
import { TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ product: Object, stocks: Array, batches: Array, serials: Array, units: Array });
const tab = ref('stock');
const totalQty = props.stocks.reduce((s, x) => s + Number(x.quantity), 0);

const unitForm = useForm({ unit_id: '', factor: '', barcode: '', selling_price: '', purchase_price: '' });
const addUnit = () => unitForm.post(route('inventory.products.units.store', props.product.id), { preserveScroll: true, onSuccess: () => unitForm.reset() });
const removeUnit = async (u) => {
    if (await confirmAction('حذف وحدة القياس هذه من الصنف؟', { danger: true, confirmText: 'حذف' })) router.delete(route('inventory.products.units.destroy', [props.product.id, u.id]), { preserveScroll: true });
};

const kitForm = useForm({ is_kit: props.product.is_kit, items: props.product.kit_items.map((k) => ({ component_id: k.component_id, name: k.component?.name_ar, quantity: Number(k.quantity) })) });
const lookup = ref('');
const results = ref([]);
const search = async () => { if (lookup.value.trim().length >= 2) results.value = (await axios.get(route('lookup.products'), { params: { q: lookup.value } })).data.data; };
const addComponent = (p) => { kitForm.items.push({ component_id: p.id, name: p.name_ar, quantity: 1 }); results.value = []; lookup.value = ''; };
const saveKit = () => kitForm.put(route('inventory.products.kit', props.product.id), { preserveScroll: true });
const trackingLabel = { none: 'بدون', batch: 'تشغيلات وتواريخ صلاحية', serial: 'أرقام تسلسلية' };
</script>

<template>
    <AppLayout>
        <Head :title="product.name_ar" />
        <PageHeader :title="product.name_ar" :subtitle="`${product.sku || ''} · التتبع: ${trackingLabel[product.tracking] || 'بدون'}${product.is_kit ? ' · طقم' : ''}`" :crumbs="[{ label: 'الأصناف', href: route('inventory.products.index') }, { label: product.name_ar }]">
            <template #actions>
                <Link :href="route('inventory.reports.item-card', { product_id: product.id })" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">كارت الصنف</Link>
                <Link v-if="$can('inventory.products.edit')" :href="route('inventory.products.edit', product.id)" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold">{{ $t('ui.edit') }}</Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <StatCard label="الرصيد الإجمالي" :value="qty(totalQty)" />
            <StatCard v-if="$can('inventory.costs.view')" label="متوسط التكلفة" :value="money(product.average_cost)" tone="amber" />
            <StatCard label="سعر البيع" :value="money(product.selling_price)" tone="indigo" />
            <StatCard v-if="$can('inventory.costs.view')" label="قيمة المخزون" :value="money(totalQty * product.average_cost)" tone="emerald" />
        </div>

        <div class="flex gap-1 mb-3 text-xs">
            <button v-for="(label, key) in { stock: 'الأرصدة', units: 'وحدات القياس', kit: 'مكونات الطقم', batches: 'التشغيلات', serials: 'الأرقام التسلسلية' }" :key="key" @click="tab = key" :class="tab === key ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200'" class="px-4 py-2 rounded-xl font-bold">{{ label }}</button>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 text-xs">
            <div class="overflow-x-auto" v-if="tab === 'stock'"><table class="w-full">
                <thead class="text-slate-500"><tr><th class="text-start py-2">المستودع</th><th class="text-start py-2">الموقع</th><th class="text-end py-2">الكمية</th></tr></thead>
                <tbody><tr v-for="s in stocks" :key="s.id" class="border-t border-slate-100"><td class="py-2">{{ s.warehouse?.name_ar }}</td><td class="py-2">{{ s.bin_location || '—' }}</td><td class="py-2 text-end font-mono">{{ qty(s.quantity) }}</td></tr></tbody>
            </table></div>

            <div v-else-if="tab === 'units'">
                <p class="text-slate-500 mb-3">الوحدة الأساسية: <b>{{ product.unit?.name_ar || 'حبة' }}</b>. أضف وحدات بديلة (كرتون، علبة...) مع معامل التحويل.</p>
                <div class="overflow-x-auto"><table class="w-full mb-3">
                    <thead class="text-slate-500"><tr><th class="text-start py-2">الوحدة</th><th class="text-end py-2">المعامل</th><th class="text-start py-2">الباركود</th><th class="text-end py-2">سعر البيع</th><th></th></tr></thead>
                    <tbody><tr v-for="u in product.units" :key="u.id" class="border-t border-slate-100"><td class="py-2">{{ u.unit?.name_ar }}</td><td class="py-2 text-end font-mono">{{ qty(u.factor) }}</td><td class="py-2 font-mono">{{ u.barcode || '—' }}</td><td class="py-2 text-end font-mono">{{ u.selling_price ? money(u.selling_price) : '—' }}</td><td class="py-2 text-end"><button v-if="$can('inventory.products.edit')" @click="removeUnit(u)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td></tr></tbody>
                </table></div>
                <form v-if="$can('inventory.products.edit')" @submit.prevent="addUnit" class="grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
                    <Field label="الوحدة" :error="unitForm.errors.unit_id"><select v-model="unitForm.unit_id" class="w-full rounded-xl border-slate-200 text-xs"><option v-for="u in units" :key="u.id" :value="u.id">{{ u.name_ar }}</option></select></Field>
                    <Field label="عدد الوحدات الأساسية" :error="unitForm.errors.factor"><input v-model="unitForm.factor" type="number" step="0.000001" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="الباركود"><input v-model="unitForm.barcode" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="سعر البيع"><input v-model="unitForm.selling_price" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="سعر الشراء"><input v-model="unitForm.purchase_price" type="number" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <button class="rounded-xl bg-indigo-600 text-white font-bold py-2">{{ $t('ui.add') }}</button>
                </form>
            </div>

            <div v-else-if="tab === 'kit'">
                <label class="flex items-center gap-2 mb-3"><input v-model="kitForm.is_kit" type="checkbox" class="rounded border-slate-300 text-indigo-600" /> هذا الصنف طقم (يُصرف من مكوناته عند البيع)</label>
                <template v-if="kitForm.is_kit">
                    <input v-model="lookup" @input="search" placeholder="إضافة مكوّن..." class="w-full rounded-xl border-slate-200 text-xs mb-2" />
                    <div v-if="results.length" class="border rounded-xl max-h-40 overflow-y-auto mb-2"><button type="button" v-for="p in results" :key="p.id" @mousedown.prevent="addComponent(p)" @keydown.enter.prevent="addComponent(p)" class="block w-full text-start px-3 py-2 hover:bg-indigo-50">{{ p.name_ar }}</button></div>
                    <table class="w-full"><tr v-for="(item, i) in kitForm.items" :key="i" class="border-t border-slate-100"><td class="py-1.5">{{ item.name }}</td><td class="py-1.5 w-32"><input v-model="item.quantity" type="number" step="0.0001" class="w-full rounded-lg border-slate-200 text-xs" /></td><td class="w-8"><button @click="kitForm.items.splice(i, 1)" class="text-slate-400 hover:text-rose-600"><TrashIcon class="w-4 h-4" /></button></td></tr></table>
                </template>
                <p v-for="(msg, key) in kitForm.errors" :key="key" class="text-rose-600 mt-1">{{ msg }}</p>
                <button v-if="$can('inventory.products.edit')" @click="saveKit" :disabled="kitForm.processing" class="mt-3 px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('ui.save') }}</button>
            </div>

            <div class="overflow-x-auto" v-else-if="tab === 'batches'"><table class="w-full">
                <thead class="text-slate-500"><tr><th class="text-start py-2">التشغيلة</th><th class="text-start py-2">المستودع</th><th class="py-2">الانتهاء</th><th class="text-end py-2">الكمية</th></tr></thead>
                <tbody><tr v-for="b in batches" :key="b.id" class="border-t border-slate-100"><td class="py-2 font-mono">{{ b.batch_number }}</td><td class="py-2">{{ b.warehouse?.name_ar }}</td><td class="py-2 text-center font-mono">{{ date(b.expiry_date) }}</td><td class="py-2 text-end font-mono">{{ qty(b.quantity) }}</td></tr></tbody>
            </table></div>

            <div v-else class="flex flex-wrap gap-2">
                <span v-for="s in serials" :key="s.id" class="px-2 py-1 rounded-lg bg-slate-100 font-mono">{{ s.serial_number }}</span>
                <p v-if="!serials.length" class="text-slate-400">لا توجد أرقام تسلسلية بالمخزون</p>
            </div>
        </div>
    </AppLayout>
</template>

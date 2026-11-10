<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import DatePicker from '@/Components/UI/DatePicker.vue';
import SelectCreate from '@/Components/UI/SelectCreate.vue';
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
import { today } from '@/utils/format';

const props = defineProps({ products: Array, boms: Array, routings: Array, warehouses: Array, salesOrders: Array, preset: Object });
const form = useForm({
    product_id: props.preset?.product_id || '', quantity: props.preset?.quantity || 1, bom_id: '', routing_id: '', warehouse_id: props.warehouses?.[0]?.id || '', output_warehouse_id: '',
    planned_start: today(), planned_end: '', sales_order_id: props.preset?.sales_order_id || '', priority: 3, notes: '',
});
useUnsavedGuard(form);
const productBoms = computed(() => props.boms.filter((b) => b.product_id === form.product_id));
watch(() => form.product_id, () => { form.bom_id = productBoms.value.find((b) => b.is_default)?.id || productBoms.value[0]?.id || ''; });
watch(() => form.bom_id, (id) => { const b = props.boms.find((x) => x.id === id); if (b?.routing_id && !form.routing_id) form.routing_id = b.routing_id; });
const submit = () => form.post(route('manufacturing.orders.store'));
const input = 'w-full text-xs rounded-xl border-slate-200 focus:border-indigo-600 focus:ring-indigo-600';
</script>

<template>
    <AppLayout>
        <Head title="أمر إنتاج جديد" />
        <PageHeader title="أمر إنتاج جديد" :crumbs="[{ label: 'التصنيع' }, { label: 'أوامر الإنتاج', href: route('manufacturing.orders.index') }, { label: 'جديد' }]" />
        <form @submit.prevent="submit" class="bg-white border border-slate-200/80 rounded-2xl p-6 space-y-5">
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <Field label="المنتج *" :error="form.errors.product_id"><SelectCreate v-model="form.product_id" :options="products" :search="{ route: 'lookup.products', params: { stockable: 1 } }" entity="product" :label="(p) => `${p.name_ar} (${p.sku})`" placeholder="— اختر —" required /></Field>
                <Field label="الكمية *" :error="form.errors.quantity"><input v-model.number="form.quantity" type="text" inputmode="decimal" data-num step="0.0001" :class="input" required /></Field>
                <Field label="قائمة المواد" :error="form.errors.bom_id"><select v-model="form.bom_id" :class="input"><option value="">— الافتراضية —</option><option v-for="b in productBoms" :key="b.id" :value="b.id">{{ b.name }} (إصدار {{ b.version }})</option></select></Field>
                <Field label="مسار التشغيل"><select v-model="form.routing_id" :class="input"><option value="">— من القائمة —</option><option v-for="r in routings" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }}</option></select></Field>
                <Field label="مستودع صرف المواد *" :error="form.errors.warehouse_id"><SelectCreate v-model="form.warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" :allow-empty="false" required /></Field>
                <Field label="مستودع المنتج التام" hint="الافتراضي: نفس مستودع المواد"><SelectCreate v-model="form.output_warehouse_id" :options="warehouses" entity="warehouse" label="name_ar" placeholder="—" /></Field>
                <Field label="بداية مخططة"><DatePicker v-model="form.planned_start" :class="input" /></Field>
                <Field label="نهاية مخططة" :error="form.errors.planned_end"><DatePicker v-model="form.planned_end" :class="input" /></Field>
                <Field label="الأولوية"><select v-model="form.priority" :class="input"><option :value="1">عاجلة جداً</option><option :value="2">عالية</option><option :value="3">عادية</option><option :value="4">منخفضة</option><option :value="5">مؤجلة</option></select></Field>
                <Field label="أمر بيع مرتبط"><select v-model="form.sales_order_id" :class="input"><option value="">—</option><option v-for="s in salesOrders" :key="s.id" :value="s.id">{{ s.order_number }}</option></select></Field>
            </div>
            <Field label="ملاحظات"><textarea v-model="form.notes" rows="2" :class="input"></textarea></Field>
            <p class="text-xs text-slate-500">تُحسب المواد المطلوبة والعمليات تلقائياً من القائمة والمسار. يُعدَّل الأمر قبل الإفراج عنه بحذفه وإنشائه من جديد.</p>
            <div class="flex gap-2 pt-2 border-t border-slate-100">
                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold disabled:opacity-50">إنشاء الأمر</button>
                <Link :href="route('manufacturing.orders.index')" class="px-6 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>

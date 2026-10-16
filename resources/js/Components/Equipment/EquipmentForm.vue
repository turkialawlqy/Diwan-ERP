<script setup>
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';

/** Fields of an equipment (create + edit). `form` is an Inertia useForm. */
defineProps({ form: Object, lookups: Object, supplier: { type: Object, default: null }, metersLocked: Boolean });
</script>

<template>
    <div class="space-y-4 text-xs">
        <div class="grid md:grid-cols-4 gap-3">
            <Field label="رمز المعدة" :error="form.errors.code"><input v-model="form.code" dir="ltr" placeholder="LD-01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            <Field label="اسم المعدة" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" placeholder="مثال: شيول كاتربلر 950" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="الفئة" :error="form.errors.category" hint="حفار، رافعة، شاحنة، مولد..."><input v-model="form.category" list="equipment-categories" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="الطراز / الموديل" :error="form.errors.model"><input v-model="form.model" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="رقم اللوحة" :error="form.errors.plate_number"><input v-model="form.plate_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="الرقم التسلسلي / الشاصي" :error="form.errors.serial_number"><input v-model="form.serial_number" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field label="مركز التكلفة" :error="form.errors.cost_center_id" hint="لتجميع مصروفات وإيرادات المعدة">
                <select v-model="form.cost_center_id" class="w-full rounded-xl border-slate-200 text-xs"><option :value="null">—</option><option v-for="c in lookups.cost_centers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name_ar }}</option></select>
            </Field>
        </div>

        <fieldset class="border border-slate-200 rounded-2xl p-3">
            <legend class="px-2 font-black text-slate-700">الملكية</legend>
            <div class="grid md:grid-cols-4 gap-3">
                <Field label="نوع الملكية">
                    <select v-model="form.ownership" class="w-full rounded-xl border-slate-200 text-xs"><option value="owned">مملوكة للشركة</option><option value="rented_in">مستأجرة من مورد</option></select>
                </Field>
                <template v-if="form.ownership === 'owned'">
                    <Field label="الأصل الثابت المرتبط" :error="form.errors.fixed_asset_id" hint="لاحتساب الإهلاك في ربحية المعدة" class="md:col-span-3">
                        <select v-model="form.fixed_asset_id" class="w-full rounded-xl border-slate-200 text-xs"><option :value="null">—</option><option v-for="a in lookups.fixed_assets" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name_ar }}</option></select>
                    </Field>
                </template>
                <template v-else>
                    <Field label="المورد المؤجّر" :error="form.errors.supplier_id"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" :initial="supplier" placeholder="ابحث عن المورد..." /></Field>
                    <Field label="سعر الإيجار من المورد" :error="form.errors.supplier_rate"><input v-model="form.supplier_rate" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field label="لكل" :error="form.errors.supplier_rate_basis">
                        <select v-model="form.supplier_rate_basis" class="w-full rounded-xl border-slate-200 text-xs"><option :value="null">—</option><option v-for="(l, k) in lookups.bases" :key="k" :value="k">{{ l }}</option></select>
                    </Field>
                </template>
            </div>
        </fieldset>

        <fieldset class="border border-slate-200 rounded-2xl p-3">
            <legend class="px-2 font-black text-slate-700">العداد وأسعار التأجير</legend>
            <div class="grid md:grid-cols-4 gap-3">
                <Field label="نوع العداد" hint="تُحسب عليه الصيانة الدورية">
                    <select v-model="form.meter_type" class="w-full rounded-xl border-slate-200 text-xs"><option value="hours">ساعات تشغيل</option><option value="km">كيلومترات</option><option value="both">ساعات وكيلومترات</option><option value="none">بدون عداد</option></select>
                </Field>
                <Field v-if="['hours', 'both'].includes(form.meter_type)" label="قراءة الساعات الحالية" :error="form.errors.current_hours" :hint="metersLocked ? 'تتغير من شاشة القراءات' : ''">
                    <input v-model="form.current_hours" type="number" min="0" step="0.1" :disabled="metersLocked" class="w-full rounded-xl border-slate-200 text-xs disabled:bg-slate-50" />
                </Field>
                <Field v-if="['km', 'both'].includes(form.meter_type)" label="قراءة الكيلومترات الحالية" :error="form.errors.current_km" :hint="metersLocked ? 'تتغير من شاشة القراءات' : ''">
                    <input v-model="form.current_km" type="number" min="0" step="0.1" :disabled="metersLocked" class="w-full rounded-xl border-slate-200 text-xs disabled:bg-slate-50" />
                </Field>
            </div>
            <div class="grid md:grid-cols-4 gap-3 mt-3">
                <Field label="سعر الساعة" :error="form.errors.hourly_rate"><input v-model="form.hourly_rate" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="سعر اليوم" :error="form.errors.daily_rate"><input v-model="form.daily_rate" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="سعر الشهر" :error="form.errors.monthly_rate"><input v-model="form.monthly_rate" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field label="تكلفة الساعة للمشاريع الداخلية" :error="form.errors.internal_hourly_cost"><input v-model="form.internal_hourly_cost" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
        </fieldset>

        <Field label="ملاحظات" :error="form.errors.notes"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
    </div>
</template>

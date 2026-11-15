<script setup>
import Field from '@/Components/UI/Field.vue';
import PartyPicker from '@/Components/Documents/PartyPicker.vue';

/** Fields of an equipment (create + edit). `form` is an Inertia useForm. */
defineProps({ form: Object, lookups: Object, supplier: { type: Object, default: null }, metersLocked: Boolean });
</script>

<template>
    <div class="space-y-4 text-xs">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <Field :label="$t('cmp.equipment_code')" :error="form.errors.code"><input v-model="form.code" dir="ltr" placeholder="LD-01" class="w-full rounded-xl border-slate-200 text-xs font-mono" /></Field>
            <Field :label="$t('cmp.equipment_name')" :error="form.errors.name" class="md:col-span-2"><input v-model="form.name" :placeholder="$t('cmp.example_caterpillar_950_loader')" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('cmp.category')" :error="form.errors.category" :hint="$t('cmp.excavator_crane_truck_generator')"><input v-model="form.category" list="equipment-categories" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('cmp.model_type')" :error="form.errors.model"><input v-model="form.model" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('cmp.plate_number')" :error="form.errors.plate_number"><input v-model="form.plate_number" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('cmp.serial_chassis_number')" :error="form.errors.serial_number"><input v-model="form.serial_number" dir="ltr" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            <Field :label="$t('cmp.cost_center')" :error="form.errors.cost_center_id" :hint="$t('cmp.to_group_the_equipment_s_expense')">
                <select v-model="form.cost_center_id" class="w-full rounded-xl border-slate-200 text-xs"><option :value="null">—</option><option v-for="c in lookups.cost_centers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name_ar }}</option></select>
            </Field>
        </div>

        <fieldset class="border border-slate-200 rounded-2xl p-3">
            <legend class="px-2 font-black text-slate-700">{{ $t('cmp.ownership') }}</legend>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('cmp.ownership_type')">
                    <select v-model="form.ownership" class="w-full rounded-xl border-slate-200 text-xs"><option value="owned">{{ $t('cmp.owned_by_the_company') }}</option><option value="rented_in">{{ $t('cmp.rented_from_a_supplier') }}</option></select>
                </Field>
                <template v-if="form.ownership === 'owned'">
                    <Field :label="$t('cmp.linked_fixed_asset')" :error="form.errors.fixed_asset_id" :hint="$t('cmp.to_include_depreciation_in_equip')" class="md:col-span-3">
                        <select v-model="form.fixed_asset_id" class="w-full rounded-xl border-slate-200 text-xs"><option :value="null">—</option><option v-for="a in lookups.fixed_assets" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name_ar }}</option></select>
                    </Field>
                </template>
                <template v-else>
                    <Field :label="$t('cmp.lessor_supplier')" :error="form.errors.supplier_id"><PartyPicker v-model="form.supplier_id" route-name="lookup.suppliers" :initial="supplier" :placeholder="$t('cmp.search_for_the_supplier')" /></Field>
                    <Field :label="$t('cmp.rental_price_from_the_supplier')" :error="form.errors.supplier_rate"><input v-model.number="form.supplier_rate" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                    <Field :label="$t('cmp.per')" :error="form.errors.supplier_rate_basis">
                        <select v-model="form.supplier_rate_basis" class="w-full rounded-xl border-slate-200 text-xs"><option :value="null">—</option><option v-for="(l, k) in lookups.bases" :key="k" :value="k">{{ l }}</option></select>
                    </Field>
                </template>
            </div>
        </fieldset>

        <fieldset class="border border-slate-200 rounded-2xl p-3">
            <legend class="px-2 font-black text-slate-700">{{ $t('cmp.meter_and_rental_rates') }}</legend>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <Field :label="$t('cmp.meter_type')" :hint="$t('cmp.used_to_schedule_periodic_mainte')">
                    <select v-model="form.meter_type" class="w-full rounded-xl border-slate-200 text-xs"><option value="hours">{{ $t('cmp.operating_hours') }}</option><option value="km">{{ $t('cmp.kilometers') }}</option><option value="both">{{ $t('cmp.hours_and_kilometers') }}</option><option value="none">{{ $t('cmp.no_meter') }}</option></select>
                </Field>
                <Field v-if="['hours', 'both'].includes(form.meter_type)" :label="$t('cmp.current_hours_reading')" :error="form.errors.current_hours" :hint="metersLocked ? 'تتغير من شاشة القراءات' : ''">
                    <input v-model.number="form.current_hours" type="text" inputmode="decimal" data-num min="0" step="0.1" :disabled="metersLocked" class="w-full rounded-xl border-slate-200 text-xs disabled:bg-slate-50" />
                </Field>
                <Field v-if="['km', 'both'].includes(form.meter_type)" :label="$t('cmp.current_kilometers_reading')" :error="form.errors.current_km" :hint="metersLocked ? 'تتغير من شاشة القراءات' : ''">
                    <input v-model.number="form.current_km" type="text" inputmode="decimal" data-num min="0" step="0.1" :disabled="metersLocked" class="w-full rounded-xl border-slate-200 text-xs disabled:bg-slate-50" />
                </Field>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
                <Field :label="$t('cmp.hourly_rate')" :error="form.errors.hourly_rate"><input v-model.number="form.hourly_rate" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('cmp.daily_rate')" :error="form.errors.daily_rate"><input v-model.number="form.daily_rate" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('cmp.monthly_rate')" :error="form.errors.monthly_rate"><input v-model.number="form.monthly_rate" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
                <Field :label="$t('cmp.hourly_cost_for_internal_project')" :error="form.errors.internal_hourly_cost"><input v-model.number="form.internal_hourly_cost" type="text" inputmode="decimal" data-num min="0" step="0.01" class="w-full rounded-xl border-slate-200 text-xs" /></Field>
            </div>
        </fieldset>

        <Field :label="$t('cmp.notes')" :error="form.errors.notes"><textarea v-model="form.notes" rows="2" class="w-full rounded-xl border-slate-200 text-xs"></textarea></Field>
    </div>
</template>

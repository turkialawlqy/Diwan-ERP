<script setup>
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ supplier: { type: Object, default: null }, whtCategories: { type: Array, default: () => [] } });

const editingSupplier = props.supplier?.id ? props.supplier : null;

const form = useForm({
    name: props.supplier?.name || '',
    company_name: props.supplier?.company_name || '',
    phone: props.supplier?.phone || '',
    email: props.supplier?.email || '',
    tax_number: props.supplier?.tax_number || '',
    commercial_register: props.supplier?.commercial_register || '',
    address: props.supplier?.address || '',
    bank_name: props.supplier?.bank_name || '',
    iban: props.supplier?.iban || '',
    opening_balance: props.supplier?.opening_balance ?? 0,
    status: props.supplier?.status || 'active',
    is_non_resident: !!props.supplier?.is_non_resident,
    wht_category: props.supplier?.wht_category || '',
    country: props.supplier?.country || '',
});
useUnsavedGuard(form);

const submitSupplier = () => (editingSupplier
    ? form.put(route('purchases.suppliers.update', editingSupplier.id))
    : form.post(route('purchases.suppliers.store')));
</script>

<template>
    <AppLayout>
        <Head :title="props.supplier?.id ? 'تعديل مورد' : 'مورد جديد'" />
        <PageHeader :title="props.supplier?.id ? 'تعديل بيانات المورد' : 'مورد جديد'" :crumbs="[{ label: 'المشتريات' }, { label: 'الموردون', href: route('purchases.suppliers.index') }, { label: props.supplier?.id ? 'تعديل' : 'جديد' }]" />

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6">
            <form @submit.prevent="submitSupplier" class="form-grid text-xs">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">اسم المورد <span class="text-rose-500">*</span></label>
                                    <input 
                                        v-model="form.name" 
                                        type="text" 
                                        required 
                                        placeholder="اسم المورد أو الشركة" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">الاسم التجاري / الشركة</label>
                                    <input 
                                        v-model="form.company_name" 
                                        type="text" 
                                        placeholder="الاسم التجاري" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">الرقم الضريبي (15 رقم)</label>
                                    <input 
                                        v-model="form.tax_number" 
                                        type="text" 
                                        maxlength="15" 
                                        placeholder="300000000000003" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">رقم السجل التجاري</label>
                                    <input 
                                        v-model="form.commercial_register" 
                                        type="text" 
                                        placeholder="1010XXXXXX" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">رقم الهاتف</label>
                                    <input 
                                        v-model="form.phone" 
                                        type="text" 
                                        placeholder="05XXXXXXXX" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">البريد الإلكتروني</label>
                                    <input 
                                        v-model="form.email" 
                                        type="email" 
                                        placeholder="vendor@company.com" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                    />
                                </div>
                                <div class="col-span-full border border-slate-200 rounded-xl p-3 grid sm:grid-cols-3 gap-3" data-wht-fields>
                                    <label class="inline-flex items-center gap-2 font-bold text-slate-700"><input type="checkbox" v-model="form.is_non_resident" data-non-resident /> مورد غير مقيم (تُستقطع الضريبة من مدفوعاته)</label>
                                    <div v-if="form.is_non_resident">
                                        <label class="block font-bold text-slate-700 mb-1">نوع الدفعة (نسبة الاستقطاع)</label>
                                        <select v-model="form.wht_category" class="w-full rounded-xl border border-slate-200 ps-3 pe-8 py-2">
                                            <option value="">أخرى</option>
                                            <option v-for="c in whtCategories" :key="c.key" :value="c.key">{{ c.label }} — {{ c.rate }}%</option>
                                        </select>
                                    </div>
                                    <div v-if="form.is_non_resident">
                                        <label class="block font-bold text-slate-700 mb-1">الدولة</label>
                                        <input v-model="form.country" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">اسم البنك</label>
                                    <input 
                                        v-model="form.bank_name" 
                                        type="text" 
                                        placeholder="مصرف الراجحي / البنك الأهلي" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 mb-1">رقم الآيبان (IBAN)</label>
                                    <input 
                                        v-model="form.iban" 
                                        type="text" 
                                        placeholder="SA0000000000000000000000" 
                                        class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono focus:border-indigo-500"
                                    />
                                </div>
                            </div>
            
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">العنوان الوطني / المقر</label>
                                <input 
                                    v-model="form.address" 
                                    type="text" 
                                    placeholder="المدينة، الحي، اسم الشارع..." 
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-indigo-500"
                                />
                            </div>
            
                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                                <Link :href="route('purchases.suppliers.index')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">إلغاء</Link>
                                <button 
                                    type="submit" 
                                    :disabled="form.processing" 
                                    class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold shadow-xs hover:shadow-indigo-500/20 disabled:opacity-50"
                                >
                                    {{ editingSupplier ? 'تحديث البيانات' : 'حفظ المورد' }}
                                </button>
                            </div>
                        </form>
        </div>
    </AppLayout>
</template>

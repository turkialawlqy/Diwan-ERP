<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ plans: Array, modules: Object });
const editing = ref(null);
const blank = { name_ar: '', name_en: '', slug: '', description_ar: '', price_monthly: 0, price_yearly: 0, max_users: 3, max_branches: 1, max_warehouses: 1, max_employees: 10, max_invoices_per_month: 500, trial_days: 14, modules: null, is_active: true, is_popular: false, sort: 0 };
const form = useForm({ ...blank });
const edit = (p) => { editing.value = p; Object.assign(form, { ...blank, ...p }); };
const reset = () => { editing.value = null; Object.assign(form, blank); };
const allModules = ref(true);
const save = () => {
    const opts = { preserveScroll: true, onSuccess: reset };
    form.transform((d) => ({ ...d, modules: d.modules && d.modules.length ? d.modules : null }));
    editing.value ? form.put(route('super-admin.plans.update', editing.value.id), opts) : form.post(route('super-admin.plans.store'), opts);
};
const inp = 'w-full rounded-xl border-slate-200 text-xs';
</script>

<template>
    <SuperAdminLayout>
        <Head title="الباقات" />
        <div class="max-w-7xl mx-auto p-4 lg:p-8 grid lg:grid-cols-3 gap-4 text-xs">
            <div class="lg:col-span-2 space-y-2">
                <div v-for="p in plans" :key="p.id" class="bg-white rounded-2xl border p-4 flex justify-between">
                    <div><b class="text-sm">{{ p.name_ar }}</b> <span class="text-slate-400 font-mono">{{ p.slug }}</span>
                        <div class="text-slate-500">{{ money(p.price_monthly) }} / شهر · {{ money(p.price_yearly) }} / سنة · {{ p.companies_count }} منشأة</div>
                        <div class="text-slate-400">{{ p.modules ? p.modules.map((m) => modules[m]).join('، ') : 'كل الوحدات' }}</div>
                    </div>
                    <button @click="edit(p)" class="text-indigo-600 font-bold h-fit">تعديل</button>
                </div>
            </div>
            <div class="bg-white rounded-2xl border p-4 space-y-2">
                <h3 class="font-black">{{ editing ? 'تعديل باقة' : 'باقة جديدة' }}</h3>
                <div class="grid grid-cols-2 gap-2">
                    <label>الاسم<input v-model="form.name_ar" :class="inp" /></label><label>Name<input v-model="form.name_en" :class="inp" /></label>
                    <label>slug<input v-model="form.slug" :class="inp" /></label><label>ترتيب<input v-model="form.sort" type="number" :class="inp" /></label>
                    <label>شهري<input v-model="form.price_monthly" type="number" :class="inp" /></label><label>سنوي<input v-model="form.price_yearly" type="number" :class="inp" /></label>
                    <label>مستخدمون<input v-model="form.max_users" type="number" :class="inp" /></label><label>فروع<input v-model="form.max_branches" type="number" :class="inp" /></label>
                    <label>مستودعات<input v-model="form.max_warehouses" type="number" :class="inp" /></label><label>موظفون<input v-model="form.max_employees" type="number" :class="inp" /></label>
                    <label>فواتير/شهر<input v-model="form.max_invoices_per_month" type="number" :class="inp" /></label><label>أيام التجربة<input v-model="form.trial_days" type="number" :class="inp" /></label>
                </div>
                <p class="text-slate-400">-1 = غير محدود</p>
                <label class="block">الوصف<textarea v-model="form.description_ar" rows="2" :class="inp"></textarea></label>
                <div class="flex flex-wrap gap-2"><label v-for="(l, k) in modules" :key="k" class="flex items-center gap-1"><input type="checkbox" :value="k" :checked="!form.modules || form.modules.includes(k)" @change="form.modules = Object.keys(modules).filter((m) => m === k ? $event.target.checked : (!form.modules || form.modules.includes(m)))" class="rounded" /> {{ l }}</label></div>
                <label class="flex items-center gap-1"><input v-model="form.is_active" type="checkbox" class="rounded" /> متاحة</label>
                <label class="flex items-center gap-1"><input v-model="form.is_popular" type="checkbox" class="rounded" /> الأكثر طلباً</label>
                <p v-for="(m, k) in form.errors" :key="k" class="text-rose-600">{{ m }}</p>
                <div class="flex gap-2"><button @click="save" class="flex-1 px-3 py-2 rounded-xl bg-slate-900 text-white font-bold">حفظ</button><button v-if="editing" @click="reset" class="px-3 py-2 rounded-xl border">إلغاء</button></div>
            </div>
        </div>
    </SuperAdminLayout>
</template>

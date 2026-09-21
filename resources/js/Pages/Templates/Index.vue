<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import {
    PrinterIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    DocumentTextIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    templates: Array,
});

const selectedTemplate = ref(props.templates?.[0] || null);

const form = useForm({
    primary_color: props.templates?.[0]?.primary_color || '#4F46E5',
    show_logo: Boolean(props.templates?.[0]?.show_logo),
    show_qr: Boolean(props.templates?.[0]?.show_qr),
    show_stamp: Boolean(props.templates?.[0]?.show_stamp),
    show_bank_details: Boolean(props.templates?.[0]?.show_bank_details),
    terms_and_conditions: props.templates?.[0]?.terms_and_conditions || '',
    footer_text: props.templates?.[0]?.footer_text || '',
});

const selectTemplate = (tmpl) => {
    selectedTemplate.value = tmpl;
    form.primary_color = tmpl.primary_color;
    form.show_logo = Boolean(tmpl.show_logo);
    form.show_qr = Boolean(tmpl.show_qr);
    form.show_stamp = Boolean(tmpl.show_stamp);
    form.show_bank_details = Boolean(tmpl.show_bank_details);
    form.terms_and_conditions = tmpl.terms_and_conditions || '';
    form.footer_text = tmpl.footer_text || '';
};

const saveTemplate = () => {
    if (!selectedTemplate.value) return;
    form.put(route('templates.update', selectedTemplate.value.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout>
        <Head title="قوالب الطباعة - الأستاذ" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-base font-bold text-slate-900">تخصيص قوالب الطباعة والفواتير</h1>
                    <p class="text-3xs text-slate-500">التحكم في هوية الفواتير الضريبية والإيصالات الحرارية وعروض الأسعار</p>
                </div>

                <!-- Breadcrumb -->
                <div class="flex items-center gap-2">
                    <nav class="flex items-center gap-1.5 text-xs text-slate-500 bg-white border border-slate-200/80 px-3 py-1.5 rounded-xl shadow-2xs">
                        <Link :href="route('dashboard')" class="hover:text-indigo-600 transition-colors">
                            <HomeIcon class="w-3.5 h-3.5" />
                        </Link>
                        <ChevronRightIcon class="w-3 h-3 text-slate-400 rotate-180" />
                        <span class="text-slate-900 font-semibold">قوالب الطباعة</span>
                    </nav>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
                <!-- Templates Selector List -->
                <div class="bg-white rounded-2xl border border-slate-100 p-4 shadow-2xs space-y-2">
                    <span class="text-3xs font-bold text-slate-400 block px-2 mb-2">القوالب المتاحة:</span>

                    <button 
                        v-for="tmpl in templates" 
                        :key="tmpl.id"
                        @click="selectTemplate(tmpl)"
                        :class="[
                            selectedTemplate?.id === tmpl.id 
                                ? 'bg-indigo-50/80 border-indigo-200 text-indigo-900 font-bold' 
                                : 'bg-slate-50 border-transparent text-slate-700 hover:bg-slate-100',
                            'w-full p-3 rounded-xl border text-start flex items-center justify-between text-xs transition'
                        ]"
                    >
                        <div class="flex items-center gap-2.5">
                            <DocumentTextIcon class="w-5 h-5 text-indigo-600" />
                            <div>
                                <div>{{ tmpl.name }}</div>
                                <span class="text-3xs font-normal text-slate-500 font-mono">{{ tmpl.template_type }}</span>
                            </div>
                        </div>
                        <span v-if="tmpl.is_default" class="px-2 py-0.5 rounded-full text-3xs bg-emerald-100 text-emerald-800 font-bold">
                            افتراضي
                        </span>
                    </button>
                </div>

                <!-- Template Settings Editor -->
                <div v-if="selectedTemplate" class="lg:col-span-2 bg-white rounded-2xl border border-slate-100 p-6 shadow-2xs space-y-5">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <PrinterIcon class="w-5 h-5" />
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-sm">{{ selectedTemplate.name }}</h3>
                                <span class="text-3xs text-slate-400">إعدادات المظهر والترويسة والخيارات</span>
                            </div>
                        </div>

                        <button 
                            @click="saveTemplate"
                            :disabled="form.processing"
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition flex items-center gap-1.5 disabled:opacity-50"
                        >
                            <CheckIcon class="w-4 h-4 stroke-2" />
                            <span>حفظ التعديلات</span>
                        </button>
                    </div>

                    <form @submit.prevent="saveTemplate" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">اللون الرئيسي للترويسة والجداول</label>
                            <div class="flex items-center gap-3">
                                <input 
                                    v-model="form.primary_color" 
                                    type="color" 
                                    class="w-10 h-10 rounded-xl border border-slate-200 cursor-pointer p-1"
                                />
                                <input 
                                    v-model="form.primary_color" 
                                    type="text" 
                                    class="w-32 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs"
                                />
                            </div>
                        </div>

                        <!-- Visibility Switches -->
                        <div class="grid grid-cols-2 gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200/60">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.show_logo" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">إظهار شعار المنشأة</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.show_qr" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">إظهار رمز التحقق ZATCA QR</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.show_stamp" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">إظهار ختم وتوقيع المنشأة</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.show_bank_details" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                <span class="font-medium text-slate-800">إظهار أرقام الحسابات البنكية</span>
                            </label>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">الشروط والأحكام الافتراضية</label>
                            <textarea 
                                v-model="form.terms_and_conditions" 
                                rows="3" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                            ></textarea>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">نص تذييل الصفحة (Footer)</label>
                            <textarea 
                                v-model="form.footer_text" 
                                rows="2" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl"
                            ></textarea>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

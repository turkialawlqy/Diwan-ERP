<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ packs: Array, branchId: Number, branches: Array, canShare: Boolean });
const branch = ref(props.branchId ?? (props.canShare ? '' : props.branches[0]?.id ?? ''));
const pick = () => router.get(route('settings.sector-packs'), branch.value ? { branch: branch.value } : {}, { preserveState: true, preserveScroll: true });
const open = reactive({});
const apply = (p) => {
    if (confirm(`تفعيل حزمة «${p.label}»؟ تُضاف الخدمات والقوالب الجاهزة ولا يُحذف أو يُعدَّل شيء موجود.`)) router.post(route('settings.sector-packs.apply', p.key), { branch_id: branch.value || null }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head title="حزم القطاعات" />
        <PageHeader title="حزم القطاعات" subtitle="إعداد جاهز لنشاطك: خدمات وخطط وأنواع أعمال وقوالب عقود تعدّلها بعد ذلك كما تشاء" :crumbs="[{ label: 'الإعدادات', href: route('settings.index') }, { label: 'حزم القطاعات' }]" />
        <div v-if="branches.length > 1 || canShare" class="mb-4 flex items-center gap-2 text-xs" data-pack-branch>
            <span class="font-bold text-slate-700">تُطبَّق الحزمة على:</span>
            <select v-model="branch" @change="pick" class="rounded-xl border-slate-200 text-xs"><option v-if="canShare" value="">كل الفروع (كتالوج مشترك)</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name_ar || b.name_en }}</option></select>
            <span class="text-slate-500">خدمات الفرع وأنواع أعماله لا يراها مستخدمو الفروع الأخرى.</span>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
            <div v-for="p in packs" :key="p.key" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-3" :data-pack="p.key">
                <div class="flex items-start justify-between gap-3">
                    <div><h2 class="font-black text-sm text-slate-900">{{ p.label }}</h2><p class="text-slate-500 mt-1">{{ p.description }}</p></div>
                    <span v-if="p.applied" class="shrink-0 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold" data-applied>مفعّلة</span>
                </div>
                <p class="text-slate-500">تفعّل: <b class="text-slate-700">{{ p.modules.join('، ') }}</b></p>
                <button type="button" class="text-indigo-600 font-bold" @click="open[p.key] = !open[p.key]" data-toggle-items>{{ open[p.key] ? 'إخفاء المحتوى' : `عرض المحتوى (${p.items.length})` }}</button>
                <ul v-if="open[p.key]" class="space-y-0.5 max-h-56 overflow-y-auto border-t border-slate-100 pt-2">
                    <li v-for="(i, n) in p.items" :key="n" class="flex justify-between gap-2"><span><span class="text-slate-400">{{ i.kind }}:</span> {{ i.name }}</span><span :class="i.exists ? 'text-slate-400' : 'text-emerald-700 font-bold'">{{ i.exists ? 'موجود' : 'جديد' }}</span></li>
                </ul>
                <button v-if="$can('admin.settings.edit')" type="button" class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold" @click="apply(p)" data-apply>{{ p.applied ? 'إعادة التطبيق (يضيف الناقص فقط)' : 'تفعيل الحزمة' }}</button>
            </div>
        </div>
    </AppLayout>
</template>

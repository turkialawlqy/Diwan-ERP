<script setup>
import RowActions from '@/Components/UI/RowActions.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { money } from '@/utils/format';

defineProps({ plans: Array, cycles: Object });
</script>

<template>
    <AppLayout>
        <Head title="خطط الاشتراكات" />
        <PageHeader title="خطط الاشتراكات" subtitle="أسعار الخدمات الدورية التي تبيعها لعملائك" :crumbs="[{ label: 'المبيعات' }, { label: 'اشتراكات العملاء', href: route('sales.subscriptions.index') }, { label: 'الخطط' }]">
            <template #actions>
                <Link v-if="$can('sales.service_plans.create')" :href="route('sales.service-plans.create')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold" data-new-plan><PlusIcon class="w-4 h-4" /> خطة جديدة</Link>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr>
                    <th class="px-4 py-2 text-start">الرمز</th><th class="px-4 py-2 text-start">الخطة</th><th class="px-4 py-2 text-start">السعر</th><th class="px-4 py-2 text-start">الدورة</th>
                    <th class="px-4 py-2 text-start">التجربة</th><th class="px-4 py-2 text-start">الاشتراكات</th><th class="px-4 py-2 text-start">الحالة</th><th class="px-4 py-2 w-16"></th>
                </tr></thead>
                <tbody>
                    <tr v-for="p in plans" :key="p.id" class="border-t border-slate-100" :data-plan="p.id">
                        <td class="px-4 py-2 font-mono font-bold text-indigo-600">{{ p.code }}</td>
                        <td class="px-4 py-2 font-bold">{{ p.name }}<span v-if="p.description" class="block font-normal text-slate-500">{{ p.description }}</span></td>
                        <td class="px-4 py-2 font-mono">{{ money(p.price) }} <span class="text-slate-400">+ {{ Number(p.tax_rate) }}%</span></td>
                        <td class="px-4 py-2">{{ cycles[p.cycle] }}{{ p.interval > 1 ? ` (كل ${p.interval})` : '' }}</td>
                        <td class="px-4 py-2">{{ p.trial_days ? `${p.trial_days} يوم` : '—' }}</td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full bg-violet-50 text-violet-700 border border-violet-100 font-bold">{{ p.live_count }} حالي / {{ p.subscriptions_count }}</span></td>
                        <td class="px-4 py-2"><span :class="p.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'" class="px-2 py-0.5 rounded-full border text-[11px] font-bold">{{ p.is_active ? 'نشطة' : 'معطلة' }}</span></td>
                        <td class="px-4 py-2 text-end"><RowActions :edit="$can('sales.service_plans.edit') ? route('sales.service-plans.edit', p.id) : null" :remove="$can('sales.service_plans.delete') && !p.subscriptions_count ? route('sales.service-plans.destroy', p.id) : null" :confirm="`حذف الخطة ${p.name}؟`" /></td>
                    </tr>
                    <tr v-if="!plans.length"><td colspan="8" class="py-10 text-center text-slate-400">لا توجد خطط بعد. أضف أول خطة لتبدأ الاشتراكات.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

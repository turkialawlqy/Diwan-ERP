<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarDaysIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { markNotesSeen } from '@/composables/useWhatsNew';
import { toLatinDigits } from '@/utils/latinDigits';

const props = defineProps({ releases: Array });
const page = usePage();
const current = page.props.brand?.version;
const types = {
    new: ['جديد', 'bg-emerald-50 text-emerald-700'],
    improved: ['تحسين', 'bg-indigo-50 text-indigo-700'],
    fixed: ['إصلاح', 'bg-amber-50 text-amber-700'],
};
const badge = (type) => types[type] || [type, 'bg-slate-100 text-slate-600'];
const monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const dayNames = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

const num = (n) => Number(n).toLocaleString('en-US');
// The release date as written (Gregorian, no time zone): «الأحد 1 نوفمبر 2026».
const longDate = (ymd) => {
    const [y, m, d] = String(ymd).split('-').map(Number);
    return `${dayNames[new Date(Date.UTC(y, m - 1, d)).getUTCDay()]} ${d} ${monthNames[m - 1]} ${y}`;
};
// Arabic counted nouns: «تحديث واحد»، «تحديثان»، «5 تحديثات»، «57 تحديثًا»، «100 تحديث».
const plural = new Intl.PluralRules('ar-SA-u-nu-latn');
const counted = (n, [one, two, few, many, other]) => {
    const form = plural.select(n);
    return { one, two }[form] ?? `${num(n)} ${{ few, many }[form] ?? other}`;
};
const updatesLabel = (n) => counted(n, ['تحديث واحد', 'تحديثان', 'تحديثات', 'تحديثًا', 'تحديث']);
const releasesLabel = (n) => counted(n, ['إصدار واحد', 'إصداران', 'إصدارات', 'إصدارًا', 'إصدار']);

// «اضافه» finds «إضافة»: the same letters whatever the hamza, the ta marbuta, the diacritics or the digits.
const norm = (s) => toLatinDigits(s).toLowerCase()
    .replace(/[\u064B-\u0652\u0670\u0640]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي');

const all = props.releases.map((r) => ({
    ...r,
    year: r.date.slice(0, 4),
    month: r.date.slice(5, 7),
    items: r.items.map(([type, text]) => ({ type, text, haystack: norm(`${badge(type)[0]} ${text} v${r.version}`) })),
}));

const dates = all.map((r) => r.date).sort();
const summary = all.length ? [
    { label: 'الإصدار الحالي', value: current ? `v${current}` : '—', ltr: true },
    { label: 'آخر تحديث', value: longDate(dates[dates.length - 1]) },
    { label: 'التحديثات', value: `${updatesLabel(all.reduce((n, r) => n + r.items.length, 0))} في ${releasesLabel(all.length)}` },
    { label: 'الإصدار الأول', value: longDate(dates[0]) },
] : [];

// Filters, all on this page: the kind of change, the year and month of the release date, and words.
const kind = ref('');
const year = ref('');
const month = ref('');
const query = ref('');
const years = [...new Set(all.map((r) => r.year))].sort().reverse();
const months = computed(() => [...new Set(all.filter((r) => !year.value || r.year === year.value).map((r) => r.month))].sort());
watch(months, (list) => { if (month.value && !list.includes(month.value)) month.value = ''; });
const filtered = computed(() => !!(kind.value || year.value || month.value || query.value.trim()));
const reset = () => { kind.value = ''; year.value = ''; month.value = ''; query.value = ''; };

// The lines of the chosen period that hold every word, before the kind filter: the kind buttons count these.
const candidates = computed(() => {
    const words = norm(query.value).split(/\s+/).filter(Boolean);
    return all
        .filter((r) => (!year.value || r.year === year.value) && (!month.value || r.month === month.value))
        .map((r) => ({ ...r, items: r.items.filter((it) => words.every((w) => it.haystack.includes(w))) }));
});
const counts = computed(() => candidates.value.reduce((c, r) => {
    r.items.forEach((it) => { c[''] += 1; c[it.type] = (c[it.type] || 0) + 1; });
    return c;
}, { '': 0 }));

// Newest month first; in a month the releases keep their order (newest first).
const sections = computed(() => {
    const byMonth = new Map();
    candidates.value.forEach((r) => {
        const items = r.items.filter((it) => !kind.value || it.type === kind.value);
        if (!items.length) return;
        const key = `${r.year}-${r.month}`;
        if (!byMonth.has(key)) byMonth.set(key, { key, label: `${monthNames[Number(r.month) - 1]} ${r.year}`, releases: [], count: 0 });
        const section = byMonth.get(key);
        section.releases.push({ ...r, items });
        section.count += items.length;
    });
    return [...byMonth.values()].sort((a, b) => b.key.localeCompare(a.key));
});
const jump = (key) => document.getElementById(`m-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });

onMounted(() => markNotesSeen(props.releases[0]?.version));
</script>

<template>
    <AppLayout>
        <Head title="أبرز التحديثات" />
        <PageHeader title="أبرز التحديثات" subtitle="كل ما تغيّر في الديوان من الإصدار الأول حتى اليوم، الأحدث أولًا" :crumbs="[{ label: 'التحديثات' }, { label: 'أبرز التحديثات' }]">
            <template #actions>
                <Link :href="route('feature-requests.index')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700">طلبات المميزات</Link>
            </template>
        </PageHeader>

        <div v-if="summary.length" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-notes-summary>
            <div v-for="s in summary" :key="s.label" class="bg-white border border-slate-200 rounded-2xl p-4">
                <p class="text-[11px] font-semibold text-slate-500">{{ s.label }}</p>
                <p class="text-sm font-black text-slate-800 mt-1" :class="s.ltr && 'font-mono'" :dir="s.ltr ? 'ltr' : null">{{ s.value }}</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-3 mb-4 flex flex-wrap items-center gap-2 print:hidden" data-notes-toolbar>
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="نوع التحديث">
                <button v-for="k in ['', 'new', 'improved', 'fixed']" :key="k" type="button" :aria-pressed="kind === k" :data-kind="k || 'all'" @click="kind = k"
                    :class="[kind === k ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50', 'px-3 py-1.5 rounded-xl border text-xs font-bold']">
                    {{ k ? types[k][0] : 'الكل' }} <span class="font-mono opacity-70">{{ num(counts[k] || 0) }}</span>
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto lg:ms-auto">
                <label class="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                    السنة
                    <select v-model="year" class="rounded-xl border-slate-200 text-xs text-slate-700" data-notes-year>
                        <option value="">الكل</option>
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                    الشهر
                    <select v-model="month" class="rounded-xl border-slate-200 text-xs text-slate-700" data-notes-month>
                        <option value="">الكل</option>
                        <option v-for="m in months" :key="m" :value="m">{{ monthNames[Number(m) - 1] }}</option>
                    </select>
                </label>
                <div class="relative flex-1 min-w-[12rem] lg:w-64 lg:flex-none">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute start-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                    <input v-model="query" type="search" placeholder="ابحث في التحديثات…" aria-label="ابحث في التحديثات" class="w-full ps-9 rounded-xl border-slate-200 text-xs" />
                </div>
                <button v-if="filtered" type="button" class="inline-flex items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-50 hover:text-slate-700" @click="reset">
                    <XMarkIcon class="w-4 h-4" /> مسح التصفية
                </button>
            </div>
        </div>

        <nav v-if="sections.length > 1" class="flex flex-wrap gap-2 mb-4 print:hidden" aria-label="الأشهر">
            <button v-for="s in sections" :key="s.key" type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:border-indigo-300 hover:text-indigo-700" @click="jump(s.key)">
                <CalendarDaysIcon class="w-4 h-4 text-slate-400" /> {{ s.label }} <span class="font-mono font-normal text-slate-400">{{ num(s.count) }}</span>
            </button>
        </nav>

        <div class="space-y-4">
            <section v-for="s in sections" :id="`m-${s.key}`" :key="s.key" :aria-labelledby="`m-${s.key}-title`" class="bg-white border border-slate-200 rounded-2xl p-4 sm:p-5 scroll-mt-20" :data-month="s.key">
                <div class="flex items-center gap-2 mb-4">
                    <h2 :id="`m-${s.key}-title`" class="text-base font-black text-slate-800">{{ s.label }}</h2>
                    <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[11px] font-bold">{{ updatesLabel(s.count) }}</span>
                </div>
                <div class="space-y-5">
                    <article v-for="r in s.releases" :key="r.version" :data-release="r.version" :data-date="r.date"
                        :class="r.version === current ? 'border-indigo-500' : 'border-slate-200'" class="border-s-[3px] ps-4 print:break-inside-avoid">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2 py-0.5 rounded-lg bg-indigo-600 text-white text-[11px] font-black font-mono" dir="ltr">v{{ r.version }}</span>
                            <span class="text-xs font-bold text-indigo-700">{{ longDate(r.date) }}</span>
                            <span v-if="r.version === current" class="px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-[11px] font-bold">الإصدار الحالي</span>
                        </div>
                        <h3 class="text-sm font-black text-slate-800 mt-1.5 mb-1">{{ r.title }}</h3>
                        <ul class="divide-y divide-dashed divide-slate-100">
                            <li v-for="(it, i) in r.items" :key="i" :data-type="it.type" class="flex items-baseline gap-2.5 py-2">
                                <span :class="badge(it.type)[1]" class="min-w-[3rem] text-center px-2 py-0.5 rounded-md text-[11px] font-bold flex-shrink-0">{{ badge(it.type)[0] }}</span>
                                <p class="text-xs text-slate-700 leading-relaxed min-w-0">{{ it.text }}</p>
                            </li>
                        </ul>
                    </article>
                </div>
            </section>

            <div v-if="!sections.length" class="bg-white border border-dashed border-slate-300 rounded-2xl p-8 text-center text-xs text-slate-500" data-notes-empty>
                {{ all.length ? 'لا توجد تحديثات تطابق التصفية.' : 'لا توجد تحديثات بعد.' }}
                <div v-if="filtered" class="mt-3">
                    <button type="button" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white font-bold text-slate-700 hover:bg-slate-50" @click="reset">مسح التصفية</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

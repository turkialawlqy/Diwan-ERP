<script setup>
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { LockClosedIcon, PlusIcon, TrashIcon, CheckIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';

const props = defineProps({ groups: Array, year: Number });

// One editable draft per rule (key "type:year"); only unused rules are editable.
const drafts = reactive({});
const key = (t, r) => `${t.type}:${r.valid_from_year}`;
const init = () => props.groups.forEach((g) => g.types.forEach((t) => t.rules.forEach((r) => {
    drafts[key(t, r)] ??= { prefix: r.prefix, pad: r.pad, start_number: r.start_number, with_year: r.with_year };
})));
init();
watch(() => props.groups, init);
const draft = (t, r) => drafts[key(t, r)];
const dirty = (t, r) => {
    const d = draft(t, r);
    return d.prefix !== r.prefix || Number(d.pad) !== r.pad || Number(d.start_number) !== r.start_number || !!d.with_year !== r.with_year;
};
const busy = ref(null);
const done = (k) => { busy.value = null; delete drafts[k]; init(); };

const save = (t, r) => {
    const k = key(t, r);
    busy.value = k;
    router.put(route('settings.numbering.update', { type: t.type, year: r.valid_from_year }), { ...draft(t, r) }, {
        preserveScroll: true, onSuccess: () => done(k), onError: () => (busy.value = null),
    });
};
const remove = (t, r) => {
    if (!confirm('حذف قاعدة الترقيم المستقبلية؟')) return;
    router.delete(route('settings.numbering.destroy', { type: t.type, year: r.valid_from_year }), { preserveScroll: true });
};

// «بادئة جديدة من سنة ...» for a used rule.
const adding = reactive({});
const open = (t) => (adding[t.type] = { year: props.year + 1, prefix: '', pad: t.rules.at(-1).pad, start_number: 1, with_year: true });
const add = (t) => {
    router.post(route('settings.numbering.add', { type: t.type }), { ...adding[t.type] }, {
        preserveScroll: true, onSuccess: () => delete adding[t.type],
    });
};
const lastRule = (t) => t.rules.at(-1);
// Live preview of a draft (same shape as NumberingRules::sample() on the server).
const sample = (d) => {
    const yy = String(new Date().getFullYear()).slice(2);
    const n = String(Number(d.start_number) || 1).padStart(Number(d.pad) || 1, '0');
    return `${d.prefix || ''}${d.with_year ? `${yy}-` : ''}${n}`;
};
const errors = (e) => Object.values(e || {}).join(' ');
</script>

<template>
    <AppLayout>
        <Head title="دليل الترميز" />
        <PageHeader title="دليل الترميز" subtitle="بادئة وعدد خانات وبداية الترقيم لكل مستند وسجل. البادئة التي استُخدمت في مستند تُقفل، ويمكن بدء بادئة جديدة من سنة لاحقة."
            :crumbs="[{ label: 'الإعدادات' }, { label: 'دليل الترميز' }]" />

        <div v-if="$page.props.errors && Object.keys($page.props.errors).length" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-xs font-bold px-4 py-3" data-numbering-errors>
            {{ errors($page.props.errors) }}
        </div>

        <section v-for="g in groups" :key="g.key" class="mb-6">
            <h2 class="text-sm font-bold text-slate-900 mb-2">{{ g.label }}</h2>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="py-3 px-4 text-start">المستند</th>
                            <th class="py-3 px-4 text-start">البادئة</th>
                            <th class="py-3 px-4 text-start">الخانات</th>
                            <th class="py-3 px-4 text-start">يبدأ من</th>
                            <th class="py-3 px-4 text-center">سنة في الرقم</th>
                            <th class="py-3 px-4 text-start">المثال</th>
                            <th class="py-3 px-4 text-start">الحالة</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="t in g.types" :key="t.type">
                            <tr v-for="r in t.rules" :key="key(t, r)" class="border-t border-slate-100" :data-type="t.type" :data-year="r.valid_from_year">
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ t.label }}
                                    <span v-if="r.valid_from_year" class="block text-3xs text-indigo-600 font-bold">من سنة {{ r.valid_from_year }}</span>
                                </td>
                                <template v-if="!r.used">
                                    <td class="py-2 px-4"><input v-model="draft(t, r).prefix" maxlength="10" class="w-28 rounded-lg border-slate-200 text-xs font-mono" dir="ltr" data-prefix /></td>
                                    <td class="py-2 px-4"><input v-model="draft(t, r).pad" type="text" inputmode="decimal" data-num class="w-16 rounded-lg border-slate-200 text-xs font-mono" /></td>
                                    <td class="py-2 px-4"><input v-model="draft(t, r).start_number" type="text" inputmode="decimal" data-num class="w-24 rounded-lg border-slate-200 text-xs font-mono" /></td>
                                    <td class="py-2 px-4 text-center">
                                        <input v-if="t.yearly" v-model="draft(t, r).with_year" type="checkbox" class="rounded border-slate-300 text-indigo-600" />
                                        <span v-else class="text-slate-300">—</span>
                                    </td>
                                </template>
                                <template v-else>
                                    <td class="py-3 px-4 font-mono font-bold" dir="ltr">{{ r.prefix }}</td>
                                    <td class="py-3 px-4 font-mono">{{ r.pad }}</td>
                                    <td class="py-3 px-4 font-mono">{{ r.start_number }}</td>
                                    <td class="py-3 px-4 text-center">{{ t.yearly ? (r.with_year ? 'نعم' : 'لا') : '—' }}</td>
                                </template>
                                <td class="py-3 px-4 font-mono text-slate-500" dir="ltr">{{ r.used ? r.sample : sample(draft(t, r)) }}</td>
                                <td class="py-3 px-4">
                                    <span v-if="r.used" class="inline-flex items-center gap-1 text-amber-700 font-bold" data-locked><LockClosedIcon class="w-3.5 h-3.5" />مستخدمة</span>
                                    <span v-else class="text-emerald-600 font-bold">متاحة للتعديل</span>
                                </td>
                                <td class="py-2 px-4 text-end whitespace-nowrap">
                                    <button v-if="!r.used" type="button" @click="save(t, r)" :disabled="!dirty(t, r) || busy === key(t, r)" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold disabled:opacity-40" data-save><CheckIcon class="w-3.5 h-3.5" />حفظ</button>
                                    <button v-if="!r.used && r.valid_from_year" type="button" @click="remove(t, r)" class="ms-1 p-1.5 rounded-lg text-rose-600 hover:bg-rose-50" title="حذف"><TrashIcon class="w-4 h-4" /></button>
                                    <button v-if="r === lastRule(t) && r.used && t.yearly && r.with_year && !adding[t.type]" type="button" @click="open(t)" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-indigo-200 text-indigo-700 font-bold" data-new-year><PlusIcon class="w-3.5 h-3.5" />بادئة جديدة من سنة</button>
                                </td>
                            </tr>
                            <tr v-if="adding[t.type]" class="border-t border-indigo-100 bg-indigo-50/40" :data-adding="t.type">
                                <td class="py-2 px-4 font-bold text-indigo-700">{{ t.label }} — جديدة</td>
                                <td class="py-2 px-4"><input v-model="adding[t.type].prefix" maxlength="10" placeholder="بادئة" class="w-28 rounded-lg border-slate-200 text-xs font-mono" dir="ltr" data-add-prefix /></td>
                                <td class="py-2 px-4"><input v-model="adding[t.type].pad" type="text" inputmode="decimal" data-num class="w-16 rounded-lg border-slate-200 text-xs font-mono" /></td>
                                <td class="py-2 px-4"><input v-model="adding[t.type].start_number" type="text" inputmode="decimal" data-num class="w-24 rounded-lg border-slate-200 text-xs font-mono" /></td>
                                <td class="py-2 px-4 text-center"><input v-model="adding[t.type].with_year" type="checkbox" class="rounded border-slate-300 text-indigo-600" /></td>
                                <td class="py-2 px-4" colspan="2">
                                    <label class="text-slate-600 font-bold">من سنة
                                        <input v-model="adding[t.type].year" type="text" inputmode="decimal" data-num class="w-20 rounded-lg border-slate-200 text-xs font-mono ms-1" data-add-year />
                                    </label>
                                </td>
                                <td class="py-2 px-4 text-end whitespace-nowrap">
                                    <button type="button" @click="add(t)" class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold" data-add-save>إضافة</button>
                                    <button type="button" @click="delete adding[t.type]" class="ms-1 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 font-bold">إلغاء</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>

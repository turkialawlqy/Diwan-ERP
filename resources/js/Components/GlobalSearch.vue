<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline';

/**
 * Global search palette (Ctrl/⌘+K or "/"): type to search every record the user may
 * see; a chip narrows the search to one kind. ↑ ↓ to move, Enter to open, Esc to close.
 * Records are registered in App\Http\Controllers\SearchController::kinds().
 */
const open = ref(false);
const query = ref('');
const kind = ref('');
const kinds = ref([]);
const groups = ref([]);
const searching = ref(false);
const active = ref(0);
const input = ref(null);
let timer = null;
let latest = 0;

const flat = computed(() => groups.value.flatMap((g) => g.items.map((item) => ({ ...item, group: g.label }))));
const kindLabel = computed(() => kinds.value.find((k) => k.key === kind.value)?.label || '');
const indexOf = (item) => flat.value.findIndex((f) => f.url === item.url && f.title === item.title);

const run = async () => {
    const ticket = ++latest;
    searching.value = true;
    try {
        const { data } = await axios.get(route('search'), { params: { q: query.value.trim(), kind: kind.value || undefined } });
        if (ticket !== latest) return;
        kinds.value = data.kinds || kinds.value;
        groups.value = data.groups || [];
        active.value = 0;
    } catch {
        if (ticket === latest) groups.value = [];
    } finally {
        if (ticket === latest) searching.value = false;
    }
};

watch([query, kind], () => {
    clearTimeout(timer);
    if (query.value.trim().length < 2) {
        groups.value = [];
        if (!kinds.value.length) run();
        return;
    }
    timer = setTimeout(run, 200);
});

const show = async () => {
    open.value = true;
    await nextTick();
    input.value?.focus();
    if (!kinds.value.length) run();
};
const close = () => { open.value = false; query.value = ''; kind.value = ''; groups.value = []; };
const go = (item) => {
    if (!item) return;
    close();
    router.visit(item.url);
};
const move = (step) => {
    if (flat.value.length) active.value = (active.value + step + flat.value.length) % flat.value.length;
};
const onBackspace = () => { if (!query.value && kind.value) kind.value = ''; };
const pick = (key) => { kind.value = kind.value === key ? '' : key; input.value?.focus(); };

const onKey = (e) => {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open.value ? close() : show(); }
    else if (e.key === '/' && !typing && !open.value) { e.preventDefault(); show(); }
};
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <button type="button" @click="show" class="h-9 flex-1 min-w-[2.25rem] sm:min-w-[10rem] lg:min-w-[14rem] max-w-md flex items-center justify-center sm:justify-start gap-2 bg-slate-50 border border-slate-200 text-slate-400 text-xs sm:text-sm rounded-lg px-2.5 sm:px-3 hover:bg-white hover:border-indigo-300 transition" :aria-label="$t('common.search')" data-search-open>
        <MagnifyingGlassIcon class="w-4 h-4 shrink-0" />
        <span class="flex-1 text-start truncate hidden sm:inline">{{ $t('common.search') }}</span>
        <kbd class="hidden md:inline text-[10px] font-mono px-1.5 py-0.5 rounded border border-slate-200 bg-white text-slate-400">Ctrl K</kbd>
    </button>

    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[70] bg-slate-900/50 backdrop-blur-sm flex items-start justify-center p-3 sm:pt-[10vh]" @mousedown.self="close" data-search-palette>
            <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[80vh]" role="dialog" aria-label="بحث">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-100">
                    <MagnifyingGlassIcon class="w-5 h-5 text-slate-400 shrink-0" />
                    <span v-if="kind" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-700 text-[11px] font-bold shrink-0">
                        {{ kindLabel }}
                        <button type="button" @click="kind = ''" aria-label="إلغاء التصنيف"><XMarkIcon class="w-3 h-3" /></button>
                    </span>
                    <input ref="input" v-model="query" type="text" :placeholder="kind ? `ابحث في ${kindLabel}...` : 'ابحث برقم أو اسم أو وصف...'" autocomplete="off"
                        class="flex-1 min-w-0 border-0 focus:ring-0 text-base text-slate-800 placeholder:text-slate-400 p-0"
                        @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go(flat[active])" @keydown.esc="close" @keydown.backspace="onBackspace" />
                    <button type="button" class="text-[11px] text-slate-400 border border-slate-200 rounded px-1.5 py-0.5 font-mono" @click="close">Esc</button>
                </div>

                <div v-if="kinds.length" class="flex flex-wrap gap-1.5 px-4 py-2 border-b border-slate-100 bg-slate-50/60" data-search-kinds>
                    <button v-for="k in kinds" :key="k.key" type="button" @click="pick(k.key)"
                        class="px-2.5 py-1 rounded-full text-[11px] font-bold border transition" :class="kind === k.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-300'">{{ k.label }}</button>
                </div>

                <div class="overflow-y-auto" role="listbox">
                    <p v-if="query.trim().length < 2" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.type_at_least_two_characters_pic') }}</p>
                    <p v-else-if="searching && !groups.length" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.searching') }}</p>
                    <p v-else-if="!groups.length" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.no_matching_results') }}</p>
                    <template v-for="g in groups" :key="g.key">
                        <p v-if="!kind" class="px-5 pt-3 pb-1 text-[11px] font-bold text-slate-400">{{ g.label }}</p>
                        <button v-for="item in g.items" :key="g.key + item.url" type="button" role="option" :aria-selected="indexOf(item) === active"
                            @mousemove="active = indexOf(item)" @click="go(item)"
                            class="w-full text-start px-5 py-3 flex items-center justify-between gap-4 transition-colors" :class="indexOf(item) === active ? 'bg-indigo-600 text-white' : 'text-slate-800 hover:bg-slate-50'">
                            <span class="min-w-0">
                                <span class="block font-bold truncate">{{ item.title }}</span>
                                <span v-if="item.subtitle" class="block text-xs truncate" :class="indexOf(item) === active ? 'text-indigo-100' : 'text-slate-400'">{{ item.subtitle }}</span>
                            </span>
                            <span v-if="item.code" class="font-mono text-xs shrink-0" dir="ltr" :class="indexOf(item) === active ? 'text-indigo-100' : 'text-slate-400'">#{{ item.code }}</span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ChevronDownIcon, DocumentTextIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline';

/**
 * Global search palette (Ctrl/⌘+K or "/"), with two tabs; ↑ ↓ to move, Enter to open, Esc to close.
 *  1. «السجلات» (the default, every time the palette opens): every record the user may see; a chip narrows the
 *     search to one kind. Records are registered in App\Http\Controllers\SearchController::kinds().
 *  2. «الصفحات»: the sections, sidebar pages and registered pages outside the sidebar that the user may open
 *     (resources/js/pageIndex.js, engine resources/js/pageSearch.js). Local, no request. A matched section is a
 *     group of its pages: Enter or a click folds it; it is never a link itself.
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

// In reading order: «السجلات» first (on the right in Arabic) and the default, then «الصفحات».
const TABS = ['records', 'pages'];
const DEFAULT_TAB = 'records';
const tab = ref(DEFAULT_TAB);

// ---- Records ----
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
    // The pages tab searches in the browser: no request for it.
    if (tab.value !== 'records') return;
    clearTimeout(timer);
    if (query.value.trim().length < 2) {
        groups.value = [];
        if (!kinds.value.length) run();
        return;
    }
    timer = setTimeout(run, 200);
});

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

// ---- Pages ----
const PAGE_STEP = 12;
const page = usePage();
const pageLimit = ref(PAGE_STEP);
const folded = ref({}); // section id → expanded, as the user set it for this query
const pageActive = ref(0);
const panel = ref(null);

// The page index code is its own chunk: fetched when the browser is idle after the page loads (or when the
// palette opens first), so it neither slows the page nor the palette.
const engine = shallowRef(null);
const engineFailed = ref(false);
let loading = null;
const loadEngine = () => (loading ??= import('@/pageSearchContext')
    .then((m) => { engine.value = m; engineFailed.value = false; })
    // Offline, or the chunk is gone after an update: say so, offer the records; the next opening tries again.
    .catch(() => { loading = null; engineFailed.value = true; }));

// The index follows the shared props (company, user, branch, permissions, modules) and the language, not the
// query: typing only searches it.
const pageIndex = computed(() => (open.value && tab.value === 'pages' && engine.value ? engine.value.pageSearchFor(page) : null));
const pageResult = computed(() => pageIndex.value?.search(query.value, { limit: pageLimit.value }) ?? null);
const pagesLoading = computed(() => open.value && tab.value === 'pages' && !engine.value && !engineFailed.value && query.value.trim().length >= 2);
const pagesUnavailable = computed(() => open.value && tab.value === 'pages' && !engine.value && engineFailed.value);

// The rows on screen, in keyboard order: a section, then its pages when expanded; pages; «more». Each knows its
// tree level, its parent and so its place among its siblings (aria-posinset / aria-setsize).
const rows = computed(() => {
    const out = [];
    for (const item of pageResult.value?.items || []) {
        if (item.kind === 'section') {
            const expanded = folded.value[item.entry.id] ?? item.expanded;
            out.push({ type: 'section', key: item.entry.id, entry: item.entry, level: 1, parent: 'root', expanded, count: item.children.length, matched: item.matched });
            if (expanded) {
                let lastPage = item.entry.id;
                for (const child of item.children) {
                    const nested = child.depth > 1;
                    out.push({ type: 'page', key: `${item.entry.id}>${child.entry.id}`, entry: child.entry, level: child.depth + 1, parent: nested ? lastPage : item.entry.id, inGroup: true });
                    if (!nested) lastPage = child.entry.id;
                }
            }
        } else {
            out.push({ type: 'page', key: item.entry.id, entry: item.entry, level: 1, parent: 'root', inGroup: false });
        }
    }
    if (pageResult.value?.more > 0) out.push({ type: 'more', key: 'more', level: 1, parent: 'root', count: pageResult.value.more });
    const sizes = {};
    for (const row of out) {
        sizes[row.parent] = (sizes[row.parent] || 0) + 1;
        row.posinset = sizes[row.parent];
    }
    for (const row of out) row.setsize = sizes[row.parent];
    return out;
});
const pageCount = computed(() => {
    const r = pageResult.value;
    if (!r || r.tooShort) return 0;
    return r.items.reduce((n, item) => n + (item.kind === 'section' ? item.children.length : 0), 0) + r.total;
});

watch(query, () => {
    pageLimit.value = PAGE_STEP;
    folded.value = {};
    pageActive.value = 0;
    if (panel.value) panel.value.scrollTop = 0;
});
watch(rows, (list) => { if (pageActive.value >= list.length) pageActive.value = Math.max(0, list.length - 1); });

// The section, sub-heading or parent page, and description under a page's name. Inside its section group a page
// needs no section, only its sub-heading (a nested page sits under its parent already).
const subline = (row) => {
    const path = row.inGroup ? (row.level === 2 ? row.entry.path.slice(1, -1) : []) : row.entry.path.slice(0, -1);
    return [path.join(' / '), row.entry.description].filter(Boolean).join(' — ');
};

const openPage = (entry, event) => {
    if (event && (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1)) {
        window.open(entry.url, '_blank', 'noopener');
        return;
    }
    close();
    router.visit(entry.url);
};
const activate = (row, event) => {
    if (!row) return;
    if (row.type === 'section') folded.value = { ...folded.value, [row.entry.id]: !row.expanded };
    else if (row.type === 'more') pageLimit.value += PAGE_STEP;
    else openPage(row.entry, event);
    // A click must not leave the keyboard behind: ↑ ↓ Enter and Esc work from the search box.
    if (open.value) input.value?.focus();
};
const onRowClick = (row, event, index) => {
    pageActive.value = index;
    activate(row, event);
};
// Middle button: the page in a new browser tab (a right click is left to the browser).
const onRowAuxClick = (row, event) => { if (row.type === 'page' && event.button === 1) activate(row, event); };
const movePage = (step) => {
    if (!rows.value.length) return;
    pageActive.value = (pageActive.value + step + rows.value.length) % rows.value.length;
    nextTick(() => document.getElementById(`gsearch-row-${pageActive.value}`)?.scrollIntoView({ block: 'nearest' }));
};

// ---- Palette ----
const dialog = ref(null);
let opener = null;

const setTab = (name, { focusTab = false } = {}) => {
    if (tab.value !== name) {
        tab.value = name;
        clearTimeout(timer);
        if (name === 'records') {
            groups.value = [];
            active.value = 0;
            if (query.value.trim().length >= 2 || !kinds.value.length) run();
        }
    }
    nextTick(() => (focusTab ? document.getElementById(`gsearch-tab-${name}`) : input.value)?.focus());
};
const otherTab = () => setTab(tab.value === 'pages' ? 'records' : 'pages', { focusTab: true });

const show = async () => {
    opener = document.activeElement;
    // Every opening starts on «السجلات», as the search did before the pages tab existed.
    tab.value = DEFAULT_TAB;
    engineFailed.value = false;
    loadEngine();
    open.value = true;
    await nextTick();
    input.value?.focus();
    if (tab.value === 'records' && !kinds.value.length) run();
};
const close = () => {
    open.value = false; query.value = ''; kind.value = ''; groups.value = [];
    const back = opener;
    opener = null;
    if (back && back !== document.body && back.isConnected) nextTick(() => back.focus?.());
};

const onArrow = (step) => (tab.value === 'records' ? move(step) : movePage(step));
const onEnter = (e) => {
    // Ctrl/⌘+Enter here belongs to the search, not to the form under it («حفظ وإضافة جديد» on create pages).
    if (e.ctrlKey || e.metaKey) e.stopPropagation();
    if (tab.value === 'records') { go(flat.value[active.value]); return; }
    if (e.isComposing) return;
    // No page index (or no page by that name): Enter looks among the records.
    if (pagesUnavailable.value) { setTab('records'); return; }
    if (!pageResult.value || pageResult.value.tooShort) return;
    if (!rows.value.length) { setTab('records'); return; }
    // Ctrl/⌘+Enter opens the page in a new browser tab, like Ctrl/⌘+click.
    activate(rows.value[pageActive.value], e.ctrlKey || e.metaKey ? e : undefined);
};
const onInputBackspace = () => { if (tab.value === 'records') onBackspace(); };
const activeId = computed(() => {
    if (tab.value === 'pages') return rows.value.length ? `gsearch-row-${pageActive.value}` : undefined;
    return flat.value.length ? `gsearch-rec-${active.value}` : undefined;
});
const controlsId = computed(() => (tab.value === 'records' ? 'gsearch-records' : rows.value.length ? 'gsearch-pages' : undefined));

// Tab and Shift+Tab stay inside the palette.
const trapFocus = (e) => {
    const nodes = [...(dialog.value?.querySelectorAll('button, [href], input, [tabindex]:not([tabindex="-1"])') || [])]
        .filter((n) => !n.disabled && n.offsetParent !== null && n.getAttribute('tabindex') !== '-1');
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
};

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
const shortcut = isMac ? '⌘ K' : 'Ctrl K';

const onKey = (e) => {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    // e.code too: with an Arabic keyboard layout Ctrl+K reports «ن» as the key. Not with Alt: Ctrl+Alt is AltGr,
    // which types letters on some layouts (ł on the Polish K key).
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key?.toLowerCase() === 'k' || e.code === 'KeyK')) { e.preventDefault(); open.value ? close() : show(); }
    else if (e.key === '/' && !typing && !open.value) { e.preventDefault(); show(); }
};
let idle = null;
onMounted(() => {
    window.addEventListener('keydown', onKey);
    idle = window.requestIdleCallback ? window.requestIdleCallback(() => loadEngine(), { timeout: 4000 }) : window.setTimeout(loadEngine, 1500);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    clearTimeout(timer);
    if (window.cancelIdleCallback && window.requestIdleCallback) window.cancelIdleCallback(idle);
    else clearTimeout(idle);
});
</script>

<template>
    <button type="button" @click="show" class="h-9 flex-1 min-w-[2.25rem] sm:min-w-[10rem] lg:min-w-[14rem] max-w-md flex items-center justify-center sm:justify-start gap-2 bg-slate-50 border border-slate-200 text-slate-400 text-xs sm:text-sm rounded-lg px-2.5 sm:px-3 hover:bg-white hover:border-indigo-300 transition" :aria-label="$t('gsearch.open')" aria-haspopup="dialog" data-search-open>
        <MagnifyingGlassIcon class="w-4 h-4 shrink-0" />
        <span class="flex-1 text-start truncate hidden sm:inline">{{ $t('gsearch.open') }}</span>
        <kbd class="hidden md:inline text-[10px] font-mono px-1.5 py-0.5 rounded border border-slate-200 bg-white text-slate-400" dir="ltr">{{ shortcut }}</kbd>
    </button>

    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[70] bg-slate-900/50 backdrop-blur-sm flex items-start justify-center p-3 sm:pt-[10vh]" @mousedown.self="close" data-search-palette>
            <div ref="dialog" class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[80vh]" role="dialog" aria-modal="true" :aria-label="$t('gsearch.dialog')" @keydown.esc="close" @keydown.tab="trapFocus">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-100">
                    <MagnifyingGlassIcon class="w-5 h-5 text-slate-400 shrink-0" />
                    <span v-if="tab === 'records' && kind" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-700 text-[11px] font-bold shrink-0">
                        {{ kindLabel }}
                        <button type="button" @click="kind = ''" aria-label="إلغاء التصنيف"><XMarkIcon class="w-3 h-3" /></button>
                    </span>
                    <input ref="input" v-model="query" type="text" :placeholder="tab === 'pages' ? $t('gsearch.pages_placeholder') : (kind ? `ابحث في ${kindLabel}...` : 'ابحث برقم أو اسم أو وصف...')" autocomplete="off"
                        role="combobox" aria-autocomplete="list" :aria-haspopup="tab === 'pages' ? 'tree' : 'listbox'" :aria-expanded="tab === 'pages' ? rows.length > 0 : groups.length > 0" :aria-controls="controlsId" :aria-activedescendant="activeId"
                        class="flex-1 min-w-0 border-0 focus:ring-0 text-base text-slate-800 placeholder:text-slate-400 p-0"
                        @keydown.down.prevent="onArrow(1)" @keydown.up.prevent="onArrow(-1)" @keydown.enter.prevent="onEnter" @keydown.backspace="onInputBackspace" />
                    <button type="button" class="text-[11px] text-slate-400 border border-slate-200 rounded px-1.5 py-0.5 font-mono" @click="close">Esc</button>
                </div>

                <div role="tablist" :aria-label="$t('gsearch.tabs')" class="flex items-center gap-1 px-3 border-b border-slate-100" @keydown.left.prevent="otherTab" @keydown.right.prevent="otherTab" data-search-tabs>
                    <button v-for="t in TABS" :id="`gsearch-tab-${t}`" :key="t" type="button" role="tab" :aria-selected="tab === t" :aria-controls="`gsearch-panel-${t}`" :tabindex="tab === t ? 0 : -1" @click="setTab(t)" :data-search-tab="t"
                        class="-mb-px inline-flex items-center gap-1.5 px-3 py-2.5 text-xs font-bold border-b-2 transition-colors" :class="tab === t ? 'text-indigo-700 border-indigo-600' : 'text-slate-500 border-transparent hover:text-slate-700'">
                        {{ t === 'pages' ? $t('gsearch.tab_pages') : $t('gsearch.tab_records') }}
                        <span v-if="t === 'pages' && pageCount" class="min-w-5 px-1.5 rounded-full bg-indigo-50 text-indigo-700 text-[10px] font-bold" data-search-pages-count>{{ pageCount }}</span>
                    </button>
                </div>

                <!-- Records: as before -->
                <div v-if="tab === 'records'" id="gsearch-panel-records" role="tabpanel" aria-labelledby="gsearch-tab-records" class="flex flex-col min-h-0">
                    <div v-if="kinds.length" class="flex flex-wrap gap-1.5 px-4 py-2 border-b border-slate-100 bg-slate-50/60" data-search-kinds>
                        <button v-for="k in kinds" :key="k.key" type="button" @click="pick(k.key)"
                            class="px-2.5 py-1 rounded-full text-[11px] font-bold border transition" :class="kind === k.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-300'">{{ k.label }}</button>
                    </div>

                    <div id="gsearch-records" class="overflow-y-auto" role="listbox">
                        <p v-if="query.trim().length < 2" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.type_at_least_two_characters_pic') }}</p>
                        <p v-else-if="searching && !groups.length" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.searching') }}</p>
                        <p v-else-if="!groups.length" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.no_matching_results') }}</p>
                        <template v-for="g in groups" :key="g.key">
                            <p v-if="!kind" class="px-5 pt-3 pb-1 text-[11px] font-bold text-slate-400">{{ g.label }}</p>
                            <button v-for="item in g.items" :id="`gsearch-rec-${indexOf(item)}`" :key="g.key + item.url" type="button" role="option" :aria-selected="indexOf(item) === active"
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

                <!-- Pages: sections (groups of their pages) and pages -->
                <div v-else id="gsearch-panel-pages" ref="panel" role="tabpanel" aria-labelledby="gsearch-tab-pages" class="overflow-y-auto overscroll-contain" data-search-pages>
                    <p v-if="pagesLoading" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('cmp.searching') }}</p>
                    <div v-else-if="pagesUnavailable" class="px-5 py-8 text-center" data-search-pages-failed>
                        <p class="text-sm text-slate-500">{{ $t('gsearch.load_failed') }}</p>
                        <button type="button" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 text-xs font-bold hover:bg-indigo-100" @click="setTab('records')">
                            <MagnifyingGlassIcon class="w-3.5 h-3.5" /> {{ $t('gsearch.tab_records') }}
                        </button>
                    </div>
                    <p v-else-if="!pageResult || pageResult.tooShort" class="px-5 py-8 text-center text-xs text-slate-400">{{ $t('gsearch.pages_hint') }}</p>
                    <div v-else-if="!rows.length" class="px-5 py-8 text-center" data-search-pages-empty>
                        <p class="text-sm text-slate-500">{{ $t('gsearch.no_pages') }}</p>
                        <button type="button" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 text-xs font-bold hover:bg-indigo-100" @click="setTab('records')" data-search-to-records>
                            <MagnifyingGlassIcon class="w-3.5 h-3.5" /> {{ $t('gsearch.search_records_for', { q: query.trim() }) }}
                        </button>
                    </div>
                    <ul v-else id="gsearch-pages" role="tree" :aria-label="$t('gsearch.results')" class="py-1">
                        <li v-for="(row, i) in rows" :id="`gsearch-row-${i}`" :key="row.key" role="treeitem" :aria-level="row.level" :aria-setsize="row.setsize" :aria-posinset="row.posinset" :aria-selected="i === pageActive" :aria-expanded="row.type === 'section' ? row.expanded : undefined"
                            :data-search-row="row.type" :data-route="row.type === 'page' ? row.entry.routeName : undefined" :data-section="row.type === 'section' ? row.entry.key : undefined"
                            @mousedown.prevent @mousemove="pageActive = i" @click="onRowClick(row, $event, i)" @auxclick="onRowAuxClick(row, $event)"
                            class="flex items-center gap-3 pe-4 cursor-pointer select-none transition-colors"
                            :class="[i === pageActive ? 'bg-indigo-600 text-white' : 'text-slate-800 hover:bg-slate-50', row.type === 'section' ? 'ps-4 py-2.5 mt-1' : row.inGroup ? (row.level > 2 ? 'ps-14 py-2' : 'ps-9 py-2') : 'ps-4 py-2.5', row.type === 'more' ? 'justify-center py-2.5' : '']">
                            <template v-if="row.type === 'section'">
                                <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" :class="i === pageActive ? 'bg-white/15 text-white' : 'bg-indigo-50 text-indigo-600'"><component :is="row.entry.icon" class="w-5 h-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-bold truncate">{{ row.entry.title }}</span>
                                    <span class="block text-xs truncate" :class="i === pageActive ? 'text-indigo-100' : 'text-slate-400'">
                                        {{ $t('gsearch.section') }} · {{ $t('gsearch.pages_count', { n: row.count }) }}<template v-if="!row.expanded && row.matched"> · {{ $t('gsearch.matched_count', { n: row.matched }) }}</template>
                                    </span>
                                </span>
                                <ChevronDownIcon class="w-4 h-4 shrink-0 transition-transform" :class="[row.expanded ? 'rotate-180' : 'rtl:rotate-90 ltr:-rotate-90', i === pageActive ? 'text-white' : 'text-slate-400']" />
                            </template>
                            <template v-else-if="row.type === 'page'">
                                <span v-if="!row.inGroup" class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" :class="i === pageActive ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500'"><component :is="row.entry.icon" class="w-5 h-5" /></span>
                                <DocumentTextIcon v-else class="w-4 h-4 shrink-0" :class="i === pageActive ? 'text-indigo-100' : 'text-slate-400'" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-bold truncate" :class="row.inGroup ? 'text-sm' : ''">{{ row.entry.title }}</span>
                                    <span v-if="subline(row)" class="block text-xs truncate" :class="i === pageActive ? 'text-indigo-100' : 'text-slate-400'">{{ subline(row) }}</span>
                                </span>
                                <span v-if="i === pageActive" class="hidden sm:inline-flex items-center gap-1 text-[11px] font-bold shrink-0">{{ $t('gsearch.go') }} <kbd class="font-mono" dir="ltr">↵</kbd></span>
                            </template>
                            <span v-else class="text-xs font-bold" :class="i === pageActive ? 'text-white' : 'text-indigo-700'">{{ $t('gsearch.show_more', { n: row.count }) }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="tab === 'pages'" class="hidden sm:block px-4 py-2 border-t border-slate-100 bg-slate-50/60 text-[11px] text-slate-400" data-search-footer>{{ $t('gsearch.keys') }}</div>
            </div>
        </div>
    </Teleport>
</template>

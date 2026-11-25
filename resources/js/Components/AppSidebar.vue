<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import BrandLogo from './BrandLogo.vue';
import WorkspaceContext from './WorkspaceContext.vue';
import { ChevronDownIcon, ChevronRightIcon, ChevronLeftIcon, XMarkIcon, ShieldCheckIcon, ArrowUpIcon, ArrowDownIcon, Bars3Icon, PencilSquareIcon } from '@heroicons/vue/24/outline';
import navigation from '@/navigation';
import { useVisibleNav } from '@/composables/useVisibleNav';
import { sidebarCollapsed, toggleSidebar } from '@/composables/useSidebar';
import { useUnreadNotes } from '@/composables/useWhatsNew';

defineProps({
    isOpen: {
        type: Boolean,
        default: false,
    }
});

defineEmits(['closeSidebar']);

const page = usePage();
const unreadNotes = useUnreadNotes();
// Text direction of the current language (the drawer slides off the start side). It follows page.props.locale,
// which Vue tracks: the language switch (useLanguage) changes <html dir> without a reload, so a computed that read
// document.documentElement.dir kept the direction the page was first rendered with.
const isRtl = computed(() => (page.props.locale || 'ar') === 'ar');

const currentPath = () => {
    // Inertia's page url (path + query) is what the server rendered; fall back to the address bar.
    const url = String(page.url || '');
    return (url ? url.split('?')[0].split('#')[0] : window.location.pathname) || window.location.pathname;
};

const isCurrent = (path, exact = false) => {
    const current = currentPath();
    if (exact) return current === path;
    return current === path || current.startsWith(path + '/');
};

// Only items the user may open (and, for country-specific ones, where the branch being worked in is);
// groups without any visible child are hidden.
const visibleItems = useVisibleNav();

// The user's own order (saved on the account): items missing from it keep their default place, after the rest.
const sortBy = (list, order, keyOf) => {
    if (!order?.length) return list;
    const rank = new Map(order.map((k, i) => [k, i]));
    return list
        .map((item, i) => [item, rank.has(keyOf(item)) ? rank.get(keyOf(item)) : 1e6 + i])
        .sort((a, b) => a[1] - b[1])
        .map(([item]) => item);
};
const savedOrder = computed(() => page.props.auth?.user?.sidebar_order || null);
const orderedItems = computed(() => sortBy(visibleItems.value, savedOrder.value?.groups, (i) => i.key)
    .map((item) => (item.children ? { ...item, children: sortBy(item.children, savedOrder.value?.children?.[item.key], (c) => c.route) } : item)));

// Edit mode: reorder groups and the links inside a group by drag and drop or with the arrows.
const editing = ref(false);
const draft = ref([]);
const saving = ref(false);
let dragging = null;
const startEdit = () => {
    sidebarCollapsed.value = false;
    draft.value = orderedItems.value.map((item) => ({ key: item.key, label: item.label, icon: item.icon, children: item.children ? item.children.map((c) => ({ route: c.route, label: c.label })) : null }));
    editing.value = true;
};
const cancelEdit = () => { editing.value = false; };
const swap = (list, from, to) => {
    if (to < 0 || to >= list.length || from === to) return;
    const [moved] = list.splice(from, 1);
    list.splice(to, 0, moved);
};
const moveGroup = (gi, dir) => swap(draft.value, gi, gi + dir);
const moveChild = (gi, ci, dir) => swap(draft.value[gi].children, ci, ci + dir);
const dragStart = (event, info) => {
    dragging = info;
    event.dataTransfer.effectAllowed = 'move';
    try { event.dataTransfer.setData('text/plain', 'sidebar'); } catch { /* some browsers need data to start a drag */ }
};
const dropOn = (info) => {
    if (!dragging || dragging.type !== info.type) return;
    if (info.type === 'group') swap(draft.value, dragging.gi, info.gi);
    else if (dragging.gi === info.gi) swap(draft.value[info.gi].children, dragging.ci, info.ci);
    dragging = null;
};
const saveOrder = () => {
    saving.value = true;
    router.post(route('preferences.sidebar.update'), {
        groups: draft.value.map((g) => g.key),
        children: Object.fromEntries(draft.value.filter((g) => g.children).map((g) => [g.key, g.children.map((c) => c.route)])),
    }, { preserveScroll: true, onSuccess: () => { editing.value = false; }, onFinish: () => { saving.value = false; } });
};
const resetOrder = () => {
    saving.value = true;
    router.delete(route('preferences.sidebar.reset'), { preserveScroll: true, onSuccess: () => { editing.value = false; }, onFinish: () => { saving.value = false; } });
};

// Collapsible menus: the group containing the current page is always open, and the groups the user
// opened by hand stay open (remembered in this browser) because every page remounts the sidebar.
const OPEN_KEY = 'diwan.sidebar.open';
const SCROLL_KEY = 'diwan.sidebar.scroll';
const readOpen = () => {
    try { return JSON.parse(localStorage.getItem(OPEN_KEY) || '[]'); } catch { return []; }
};
const saveOpen = () => {
    try { localStorage.setItem(OPEN_KEY, JSON.stringify(Object.keys(openMenus.value).filter((k) => openMenus.value[k]))); } catch { /* private mode */ }
};
const remembered = readOpen();
const openMenus = ref(Object.fromEntries(navigation
    .filter((item) => item.children)
    .map((item) => [item.key, remembered.includes(item.key) || item.children.some((child) => isCurrent(child.path, child.exact))])));

const toggleMenu = (menuKey) => {
    // On the icon rail a group has no room for its links: widen the rail first.
    if (sidebarCollapsed.value) {
        sidebarCollapsed.value = false;
        openMenus.value[menuKey] = true;
        saveOpen();
        return;
    }
    openMenus.value[menuKey] = !openMenus.value[menuKey];
    saveOpen();
};

// Keep the selected page in view: restore the menu's scroll position, then bring the current link
// into view when it sits outside the visible part of a long menu.
const navEl = ref(null);
onMounted(() => {
    nextTick(() => {
        const nav = navEl.value;
        if (!nav) return;
        try { nav.scrollTop = Number(sessionStorage.getItem(SCROLL_KEY)) || 0; } catch { /* ignore */ }
        const active = nav.querySelector('[data-nav-current]');
        if (active) {
            const box = nav.getBoundingClientRect();
            const rect = active.getBoundingClientRect();
            if (rect.top < box.top || rect.bottom > box.bottom) active.scrollIntoView({ block: 'center' });
        }
    });
});
const rememberScroll = () => {
    try { sessionStorage.setItem(SCROLL_KEY, String(navEl.value?.scrollTop || 0)); } catch { /* ignore */ }
};

// The rail is a desktop feature: the mobile drawer is always full width.
const slim = computed(() => sidebarCollapsed.value);
</script>

<template>
    <!-- Mobile Backdrop -->
    <div
        v-if="isOpen"
        @click="$emit('closeSidebar')"
        class="fixed inset-0 bg-navy-900/60 backdrop-blur-sm z-40 lg:hidden transition-opacity"
    ></div>

    <!-- Sidebar Container (Right side in RTL, Left side in LTR) -->
    <aside
        data-sidebar
        :class="[
            isOpen
                ? 'translate-x-0'
                : (isRtl ? 'translate-x-full lg:translate-x-0' : '-translate-x-full lg:translate-x-0'),
            slim ? 'lg:w-20' : 'lg:w-72',
            'fixed top-0 bottom-0 start-0 w-72 bg-navy-850 text-slate-300 border-e border-slate-800 z-50 flex flex-col transition-all duration-300 ease-in-out shadow-xl'
        ]"
    >
        <!-- Brand header + rail toggle -->
        <div :class="slim ? 'lg:flex-col lg:justify-center lg:gap-1.5 lg:px-0' : ''" class="h-20 flex items-center justify-between px-4 border-b border-slate-800/80 bg-navy-900/60 flex-shrink-0 gap-2">
            <Link :href="route('dashboard')" class="flex items-center min-w-0 overflow-hidden">
                <BrandLogo size="sm" :showSubtitle="!slim" :white="true" :compact="slim" />
            </Link>

            <button
                @click="toggleSidebar"
                type="button"
                data-sidebar-toggle
                class="hidden lg:flex w-8 h-8 rounded-lg bg-slate-800/80 text-slate-400 hover:text-white hover:bg-slate-700 items-center justify-center transition-colors flex-shrink-0"
                :title="slim ? $t('sidebar_edit.expand') : $t('sidebar_edit.collapse')"
            >
                <!-- Points to the start edge when collapsing and away from it when expanding; rtl: mirrors it for Arabic. -->
                <component :is="slim ? ChevronRightIcon : ChevronLeftIcon" class="w-4 h-4 rtl:rotate-180" />
            </button>
            <button
                @click="$emit('closeSidebar')"
                class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
            >
                <XMarkIcon class="w-5 h-5" />
            </button>
        </div>

        <!-- Working context: company, branch and fiscal year (fixed, above the menu) -->
        <div v-if="page.props.tenant" :class="slim ? 'lg:px-0' : ''" class="px-3 py-3 border-b border-slate-800/80 flex-shrink-0">
            <WorkspaceContext :slim="slim" />
        </div>

        <!-- Navigation (the only part that scrolls, permission aware) -->
        <nav ref="navEl" @scroll.passive="rememberScroll" class="flex-1 min-h-0 overflow-y-auto px-3 py-4 space-y-1 text-sm select-none custom-scrollbar">
            <template v-if="editing">
                <div class="flex flex-wrap items-center gap-1.5 pb-2 text-xs sticky top-0 bg-navy-850 z-10" data-sidebar-edit-bar>
                    <button type="button" :disabled="saving" @click="saveOrder" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white font-bold hover:bg-brand-700 disabled:opacity-50" data-sidebar-save>{{ $t('sidebar_edit.save') }}</button>
                    <button type="button" @click="cancelEdit" class="px-3 py-1.5 rounded-lg bg-slate-700 text-slate-200 hover:bg-slate-600" data-sidebar-cancel>{{ $t('sidebar_edit.cancel') }}</button>
                    <button v-if="savedOrder" type="button" :disabled="saving" @click="resetOrder" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-white" data-sidebar-reset>{{ $t('sidebar_edit.reset') }}</button>
                </div>
                <p class="text-[11px] text-slate-500 pb-2">{{ $t('sidebar_edit.hint') }}</p>
                <div v-for="(g, gi) in draft" :key="g.key" data-sidebar-group-row class="mb-1 rounded-xl bg-slate-800/40" draggable="true" @dragstart.stop="dragStart($event, { type: 'group', gi })" @dragover.prevent @drop.prevent.stop="dropOn({ type: 'group', gi })">
                    <div class="flex items-center gap-2 px-2.5 py-2">
                        <Bars3Icon class="w-4 h-4 text-slate-500 cursor-grab flex-shrink-0" />
                        <component :is="g.icon" v-if="g.icon" class="w-5 h-5 text-slate-300 flex-shrink-0" />
                        <span class="flex-1 font-semibold text-slate-200 truncate">{{ $t(g.label) }}</span>
                        <button type="button" :aria-label="$t('sidebar_edit.up')" class="p-1 rounded hover:bg-slate-700 text-slate-300" data-sidebar-group-up @click="moveGroup(gi, -1)"><ArrowUpIcon class="w-4 h-4" /></button>
                        <button type="button" :aria-label="$t('sidebar_edit.down')" class="p-1 rounded hover:bg-slate-700 text-slate-300" data-sidebar-group-down @click="moveGroup(gi, 1)"><ArrowDownIcon class="w-4 h-4" /></button>
                    </div>
                    <div v-if="g.children" class="ps-6 pe-2 pb-2 space-y-0.5">
                        <div v-for="(c, ci) in g.children" :key="c.route" data-sidebar-child-row class="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-slate-900/40 text-[13px]" draggable="true" @dragstart.stop="dragStart($event, { type: 'child', gi, ci })" @dragover.prevent @drop.prevent.stop="dropOn({ type: 'child', gi, ci })">
                            <Bars3Icon class="w-3.5 h-3.5 text-slate-500 cursor-grab flex-shrink-0" />
                            <span class="flex-1 text-slate-300 truncate">{{ $t(c.label) }}</span>
                            <button type="button" :aria-label="$t('sidebar_edit.up')" class="p-0.5 rounded hover:bg-slate-700 text-slate-400" data-sidebar-child-up @click="moveChild(gi, ci, -1)"><ArrowUpIcon class="w-3.5 h-3.5" /></button>
                            <button type="button" :aria-label="$t('sidebar_edit.down')" class="p-0.5 rounded hover:bg-slate-700 text-slate-400" data-sidebar-child-down @click="moveChild(gi, ci, 1)"><ArrowDownIcon class="w-3.5 h-3.5" /></button>
                        </div>
                    </div>
                </div>
            </template>
            <template v-else>
            <template v-for="item in orderedItems" :key="item.key">
                <!-- Single link -->
                <Link
                    v-if="!item.children"
                    :href="route(item.route)"
                    :title="slim ? $t(item.label) : ''"
                    :data-nav-current="isCurrent(item.path) ? '' : null"
                    :class="[
                        isCurrent(item.path)
                            ? 'bg-gradient-to-l from-brand-600 to-brand-700 text-white font-semibold shadow-lg shadow-brand-900/40'
                            : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60',
                        slim ? 'lg:justify-center' : '',
                        'flex items-center gap-3 px-3.5 py-3 rounded-xl transition-all duration-150 group'
                    ]"
                >
                    <component :is="item.icon" class="w-6 h-6 flex-shrink-0 transition-transform group-hover:scale-110" />
                    <span :class="slim ? 'lg:hidden' : ''" class="whitespace-nowrap">{{ $t(item.label) }}</span>
                </Link>

                <!-- Collapsible group -->
                <div v-else>
                    <button
                        @click="toggleMenu(item.key)"
                        :title="slim ? $t(item.label) : ''"
                        :class="[
                            item.children.some((c) => isCurrent(c.path, c.exact)) ? 'text-white' + (slim ? ' bg-white/10 ring-1 ring-brand-500/60' : '') : 'text-slate-400 hover:text-slate-200',
                            slim ? 'lg:justify-center' : 'justify-between',
                            'w-full flex items-center px-3.5 py-3 rounded-xl hover:bg-slate-800/60 transition-colors group'
                        ]"
                    >
                        <div class="flex items-center gap-3">
                            <component :is="item.icon" :class="[item.color === 'emerald' ? 'text-emerald-400' : 'text-brand-400', 'w-6 h-6 flex-shrink-0 transition-transform group-hover:scale-110']" />
                            <span :class="slim ? 'lg:hidden' : ''" class="font-semibold whitespace-nowrap">{{ $t(item.label) }}</span>
                            <span v-if="item.key === 'updates' && unreadNotes" class="w-2 h-2 rounded-full bg-rose-500 flex-shrink-0" data-notes-dot></span>
                        </div>
                        <ChevronDownIcon
                            :class="[openMenus[item.key] ? 'rotate-180' : '', slim ? 'lg:hidden' : '', 'w-4 h-4 text-slate-500 transition-transform']"
                        />
                    </button>

                    <div v-show="openMenus[item.key] && !slim" class="mt-1 ps-9 pe-2 space-y-0.5 text-[13px]">
                        <template v-for="(child, ci) in item.children" :key="child.route">
                        <p v-if="child.section && child.section !== item.children[ci - 1]?.section" class="px-3 pt-2 pb-0.5 text-[11px] font-bold text-slate-500" data-nav-section>{{ $t(child.section) }}</p>
                        <Link
                            :href="route(child.route)"
                            :data-nav-current="isCurrent(child.path, child.exact) ? '' : null"
                            :class="[
                                isCurrent(child.path, child.exact)
                                    ? 'text-white font-bold bg-white/10'
                                    : 'text-slate-400 hover:text-slate-100 hover:bg-slate-800/50',
                                'block px-3 py-2 rounded-lg transition'
                            ]"
                        >
                            {{ $t(child.label) }}
                        </Link>
                        </template>
                    </div>
                </div>
            </template>
            </template>

            <!-- Platform administrator link -->
            <div v-if="page.props.auth?.user?.is_super_admin" class="pt-3 mt-3 border-t border-slate-800">
                <Link
                    :href="route('super-admin.dashboard')"
                    :title="slim ? $t('nav.super_admin') : ''"
                    :class="[slim ? 'lg:justify-center' : '', 'flex items-center gap-3 px-3.5 py-3 rounded-xl bg-purple-500/15 text-purple-300 font-bold hover:bg-purple-500/25 transition-colors']"
                >
                    <ShieldCheckIcon class="w-6 h-6 flex-shrink-0" />
                    <span :class="slim ? 'lg:hidden' : ''">{{ $t('nav.super_admin') }}</span>
                </Link>
            </div>
        </nav>

        <button v-if="!editing && !slim" type="button" @click="startEdit" class="mx-3 mb-2 flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs text-slate-400 hover:text-white hover:bg-slate-800/60 border border-slate-800" data-sidebar-edit>
            <PencilSquareIcon class="w-4 h-4" /> {{ $t('sidebar_edit.edit') }}
        </button>

        <!-- Footer: server state and version -->
        <div class="p-4 border-t border-slate-800/80 bg-navy-900/40 text-xs flex-shrink-0">
            <div :class="slim ? 'lg:hidden' : ''" class="flex items-center justify-between text-slate-400">
                <span class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    {{ $page.props.brand?.local_install ? 'يعمل على هذا الجهاز' : 'الخادم: متصل' }}
                </span>
                <span class="text-[10px] text-slate-500 font-mono" data-version>
                    v{{ $page.props.brand?.version }}
                    <span v-if="$page.props.update?.available && $page.props.update?.latest" class="text-emerald-400" :title="$t('cmp.a_newer_version_is_available_upd')">· متوفر v{{ $page.props.update.latest.version }}</span>
                </span>
            </div>
            <div :class="slim ? 'lg:flex' : 'hidden'" class="hidden justify-center">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" :title="$t('cmp.online')"></span>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #334155;
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #475569;
}
</style>

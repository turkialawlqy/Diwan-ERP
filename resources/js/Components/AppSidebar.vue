<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BrandLogo from './BrandLogo.vue';
import WorkspaceContext from './WorkspaceContext.vue';
import { ChevronDownIcon, ChevronRightIcon, ChevronLeftIcon, XMarkIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline';
import navigation from '@/navigation';
import { canRoute } from '@/permissions';
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
// The text direction the page was actually rendered with (the drawer slides off the start side).
const isRtl = computed(() => (typeof document !== 'undefined' ? document.documentElement.dir : 'rtl') === 'rtl' || (!document?.documentElement?.dir && (page.props.locale || 'ar') === 'ar'));

const isCurrent = (path, exact = false) => {
    const current = window.location.pathname;
    if (exact) return current === path;
    return current === path || current.startsWith(path + '/');
};

// Only items the user may open (and, for country-specific ones, where the branch being worked in is);
// groups without any visible child are hidden.
const inCountry = (item) => !item.feature || (page.props.features || []).includes(item.feature);
const visibleItems = computed(() => navigation
    .map((item) => item.children
        ? { ...item, children: item.children.filter((child) => canRoute(child.route) && inCountry(child)) }
        : item)
    .filter((item) => item.children ? item.children.length > 0 : canRoute(item.route)));

// Collapsible menus: open the group containing the current page.
const openMenus = ref(Object.fromEntries(navigation
    .filter((item) => item.children)
    .map((item) => [item.key, item.children.some((child) => isCurrent(child.path, child.exact))])));

const toggleMenu = (menuKey) => {
    // On the icon rail a group has no room for its links: widen the rail first.
    if (sidebarCollapsed.value) {
        sidebarCollapsed.value = false;
        openMenus.value[menuKey] = true;
        return;
    }
    openMenus.value[menuKey] = !openMenus.value[menuKey];
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
                :title="slim ? 'توسيع القائمة' : 'طيّ القائمة'"
            >
                <component :is="(slim ? !isRtl : isRtl) ? ChevronLeftIcon : ChevronRightIcon" class="w-4 h-4" />
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
        <nav class="flex-1 min-h-0 overflow-y-auto px-3 py-4 space-y-1 text-sm select-none custom-scrollbar">
            <template v-for="item in visibleItems" :key="item.key">
                <!-- Single link -->
                <Link
                    v-if="!item.children"
                    :href="route(item.route)"
                    :title="slim ? $t(item.label) : ''"
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
                            item.children.some((c) => isCurrent(c.path, c.exact)) ? 'text-white' : 'text-slate-400 hover:text-slate-200',
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
                        <Link
                            v-for="child in item.children"
                            :key="child.route"
                            :href="route(child.route)"
                            :class="[
                                isCurrent(child.path, child.exact)
                                    ? 'text-white font-bold bg-white/10'
                                    : 'text-slate-400 hover:text-slate-100 hover:bg-slate-800/50',
                                'block px-3 py-2 rounded-lg transition'
                            ]"
                        >
                            {{ $t(child.label) }}
                        </Link>
                    </div>
                </div>
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

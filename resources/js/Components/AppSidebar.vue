<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BrandLogo from './BrandLogo.vue';
import { ChevronDownIcon, XMarkIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline';
import navigation from '@/navigation';
import { canRoute } from '@/permissions';

defineProps({
    isOpen: {
        type: Boolean,
        default: false,
    }
});

defineEmits(['closeSidebar']);

const page = usePage();
const isRtl = computed(() => {
    return (page.props.locale || document.documentElement.lang || 'ar') === 'ar';
});

const isCurrent = (path, exact = false) => {
    const current = window.location.pathname;
    if (exact) return current === path;
    return current === path || current.startsWith(path + '/');
};

// Only items the user may open (and, for country-specific ones, where the company has a branch);
// groups without any visible child are hidden.
const inCountry = (item) => !item.country || (page.props.tenant?.countries || ['SA']).includes(item.country);
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
    openMenus.value[menuKey] = !openMenus.value[menuKey];
};
</script>

<template>
    <!-- Mobile Backdrop -->
    <div 
        v-if="isOpen" 
        @click="$emit('closeSidebar')"
        class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden transition-opacity"
    ></div>

    <!-- Sidebar Container (Right side in RTL, Left side in LTR) -->
    <aside 
        :class="[
            isOpen 
                ? 'translate-x-0' 
                : (isRtl ? 'translate-x-full lg:translate-x-0' : '-translate-x-full lg:translate-x-0'),
            'fixed top-0 bottom-0 start-0 w-64 bg-white border-e border-slate-200 z-50 flex flex-col transition-transform duration-300 ease-in-out shadow-lg lg:shadow-none'
        ]"
    >
        <!-- Sidebar Brand Header -->
        <div class="h-16 flex items-center justify-between px-5 border-b border-slate-100 flex-shrink-0">
            <Link :href="route('dashboard')" class="flex items-center">
                <BrandLogo size="sm" :showSubtitle="true" />
            </Link>

            <button 
                @click="$emit('closeSidebar')" 
                class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
            >
                <XMarkIcon class="w-5 h-5" />
            </button>
        </div>

        <!-- Sidebar Navigation Items (Scrollable, permission aware) -->
        <div class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-xs select-none custom-scrollbar">
            <template v-for="item in visibleItems" :key="item.key">
                <!-- Single link -->
                <Link
                    v-if="!item.children"
                    :href="route(item.route)"
                    :class="[
                        isCurrent(item.path)
                            ? 'bg-indigo-50 text-indigo-700 font-bold'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium',
                        'flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors'
                    ]"
                >
                    <component :is="item.icon" class="w-5 h-5 text-indigo-600 flex-shrink-0" />
                    <span>{{ $t(item.label) }}</span>
                </Link>

                <!-- Collapsible group -->
                <div v-else>
                    <button
                        @click="toggleMenu(item.key)"
                        class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-slate-700 hover:bg-slate-50 font-medium transition-colors"
                    >
                        <div class="flex items-center gap-3">
                            <component :is="item.icon" :class="[item.color === 'emerald' ? 'text-emerald-600' : 'text-indigo-600', 'w-5 h-5 flex-shrink-0']" />
                            <span class="font-semibold">{{ $t(item.label) }}</span>
                        </div>
                        <ChevronDownIcon
                            :class="[openMenus[item.key] ? 'rotate-180' : '', 'w-4 h-4 text-slate-400 transition-transform']"
                        />
                    </button>

                    <div v-show="openMenus[item.key]" class="mt-1 ps-8 pe-2 space-y-1">
                        <Link
                            v-for="child in item.children"
                            :key="child.route"
                            :href="route(child.route)"
                            :class="[
                                isCurrent(child.path, child.exact)
                                    ? (item.color === 'emerald' ? 'text-emerald-600 font-bold bg-emerald-50/50' : 'text-indigo-600 font-bold bg-indigo-50/50')
                                    : 'text-slate-500 hover:text-slate-900',
                                'block px-2.5 py-1.5 rounded-lg transition'
                            ]"
                        >
                            {{ $t(child.label) }}
                        </Link>
                    </div>
                </div>
            </template>

            <!-- Super Admin Link (if Super Admin) -->
            <div v-if="page.props.auth?.user?.is_super_admin" class="pt-3 border-t border-slate-100">
                <Link
                    :href="route('super-admin.dashboard')"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-purple-50 text-purple-700 font-bold hover:bg-purple-100 transition-colors"
                >
                    <ShieldCheckIcon class="w-5 h-5 text-purple-600 flex-shrink-0" />
                    <span>{{ $t('nav.super_admin') }}</span>
                </Link>
            </div>
        </div>

        <!-- Sidebar Footer Info -->
        <div class="p-3 border-t border-slate-100 text-center text-[10px] text-slate-400 flex-shrink-0">
            <span>{{ $page.props.brand?.name }} v1.0.0 © {{ new Date().getFullYear() }}</span>
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
    background: #E2E8F0;
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #CBD5E1;
}
</style>

<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link, usePage, router } from '@inertiajs/vue3';
import { 
    Bars3Icon, 
    MagnifyingGlassIcon, 
    BellIcon, 
    DeviceTabletIcon,
    BuildingStorefrontIcon,
    ChevronDownIcon,
    UserCircleIcon,
    ArrowRightOnRectangleIcon,
    Cog6ToothIcon,
    ShieldCheckIcon
} from '@heroicons/vue/24/outline';
import LanguageSwitcher from './LanguageSwitcher.vue';
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue';

defineEmits(['toggleSidebar']);

const page = usePage();
const user = page.props.auth?.user;
const tenant = page.props.tenant;

// Global search: debounced lookup across the records the user may view.
const searchQuery = ref('');
const results = ref([]);
const searchOpen = ref(false);
const searching = ref(false);
const active = ref(-1);
const flat = computed(() => results.value.flatMap((g) => g.items));
let timer = null;
let latest = 0;

watch(searchQuery, (value) => {
    clearTimeout(timer);
    active.value = -1;
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const ticket = ++latest;
        searching.value = true;
        try {
            const { data } = await axios.get(route('search'), { params: { q: value.trim() } });
            if (ticket === latest) {
                results.value = data.groups || [];
                searchOpen.value = true;
            }
        } catch {
            if (ticket === latest) results.value = [];
        } finally {
            if (ticket === latest) searching.value = false;
        }
    }, 250);
});

const go = (item) => {
    if (!item) return;
    searchOpen.value = false;
    searchQuery.value = '';
    router.visit(item.url);
};
const move = (step) => {
    if (!flat.value.length) return;
    searchOpen.value = true;
    active.value = (active.value + step + flat.value.length) % flat.value.length;
};
const handleSearch = () => go(flat.value[active.value] || flat.value[0]);
const indexOf = (item) => flat.value.indexOf(item);
const closeSoon = () => setTimeout(() => (searchOpen.value = false), 150);

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <header class="h-16 bg-white border-b border-slate-200 sticky top-0 z-30 px-4 lg:px-8 flex items-center justify-between shadow-[0_1px_3px_0_rgba(0,0,0,0.02)]">
        <!-- Left / Right depending on RTL: Sidebar Toggle & Search Bar -->
        <div class="flex items-center gap-3 lg:gap-4 flex-1 max-w-xl">
            <button 
                @click="$emit('toggleSidebar')" 
                type="button" 
                class="lg:hidden p-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
            >
                <Bars3Icon class="w-6 h-6" />
            </button>

            <!-- Global Search Bar -->
            <form @submit.prevent="handleSearch" class="relative w-full max-w-md hidden sm:block">
                <span class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none text-slate-400">
                    <MagnifyingGlassIcon class="w-4 h-4" />
                </span>
                <input 
                    v-model="searchQuery"
                    type="search" 
                    :placeholder="$t('common.search')" 
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="searchOpen && searchQuery.trim().length >= 2"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.esc="searchOpen = false"
                    @focus="searchOpen = true"
                    @blur="closeSoon"
                    class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-lg ps-9 pe-4 py-2 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all placeholder:text-slate-400"
                />
                <div v-if="searchOpen && searchQuery.trim().length >= 2" class="absolute top-full mt-1 inset-x-0 bg-white border border-slate-200 rounded-xl shadow-lg max-h-[70vh] overflow-y-auto z-40 text-xs" role="listbox" data-search-results>
                    <p v-if="searching && !results.length" class="p-3 text-slate-400">جارٍ البحث...</p>
                    <p v-else-if="!results.length" class="p-3 text-slate-400">لا توجد نتائج مطابقة.</p>
                    <div v-for="g in results" :key="g.key" class="py-1">
                        <p class="px-3 py-1 text-3xs font-bold text-slate-400">{{ g.label }}</p>
                        <button v-for="item in g.items" :key="item.url" type="button" role="option" :aria-selected="indexOf(item) === active"
                            @mousedown.prevent="go(item)" :class="indexOf(item) === active ? 'bg-indigo-50' : 'hover:bg-slate-50'" class="w-full text-start px-3 py-1.5 flex items-center justify-between gap-3">
                            <span class="font-bold text-slate-700 truncate">{{ item.title }}</span>
                            <span class="text-slate-400 truncate font-mono">{{ item.subtitle }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Center / End: Company Badge, POS Button, Language, Notifications, User Menu -->
        <div class="flex items-center gap-2 sm:gap-3">
            <!-- Active Company & Branch Pill -->
            <div v-if="tenant" class="hidden md:flex items-center gap-2 bg-indigo-50/80 text-indigo-900 border border-indigo-100 px-3 py-1.5 rounded-lg text-xs font-medium">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <BuildingStorefrontIcon class="w-4 h-4 text-indigo-600" />
                <span class="font-bold">{{ tenant.name }}</span>
            </div>

            <!-- Quick POS Cashier Action Button -->
            <Link 
                :href="route('pos.index')"
                class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-2 rounded-lg shadow-sm transition-colors"
                title="فتح نقطة البيع (الكاشير)"
            >
                <DeviceTabletIcon class="w-4 h-4" />
                <span class="hidden sm:inline">نقطة البيع (POS)</span>
            </Link>

            <!-- Language Switcher -->
            <LanguageSwitcher />

            <!-- Notifications Icon -->
            <Link
                :href="route('notifications.index')"
                class="p-2 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors relative"
                title="الإشعارات"
            >
                <BellIcon class="w-5 h-5" />
                <span v-if="$page.props.unreadNotifications" class="absolute -top-0.5 -end-0.5 min-w-4 h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full ring-2 ring-white flex items-center justify-center">{{ $page.props.unreadNotifications > 99 ? '99+' : $page.props.unreadNotifications }}</span>
            </Link>

            <!-- User Dropdown Menu -->
            <Menu as="div" class="relative">
                <MenuButton class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-50 transition border border-transparent hover:border-slate-200">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-indigo-600 to-indigo-700 text-white flex items-center justify-center font-bold text-xs shadow-sm">
                        {{ user?.name ? user.name.charAt(0) : 'U' }}
                    </div>
                    <div class="hidden xl:flex flex-col text-start leading-tight">
                        <span class="text-xs font-bold text-slate-800">{{ user?.name }}</span>
                        <span class="text-[10px] text-slate-500">{{ user?.is_super_admin ? 'مدير النظام' : 'مدير المؤسسة' }}</span>
                    </div>
                    <ChevronDownIcon class="w-4 h-4 text-slate-400" />
                </MenuButton>

                <transition
                    enter-active-class="transition duration-100 ease-out"
                    enter-from-class="transform scale-95 opacity-0"
                    enter-to-class="transform scale-100 opacity-100"
                    leave-active-class="transition duration-75 ease-in"
                    leave-from-class="transform scale-100 opacity-100"
                    leave-to-class="transform scale-95 opacity-0"
                >
                    <MenuItems class="absolute end-0 mt-2 w-56 origin-top-right rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-black/5 focus:outline-none z-50 text-xs divide-y divide-slate-100">
                        <div class="px-3 py-2.5">
                            <p class="font-bold text-slate-900">{{ user?.name }}</p>
                            <p class="text-slate-500 text-[11px] truncate">{{ user?.email }}</p>
                            <span v-if="user?.is_super_admin" class="mt-1.5 inline-flex items-center gap-1 bg-purple-50 text-purple-700 border border-purple-200 text-[10px] font-semibold px-2 py-0.5 rounded">
                                <ShieldCheckIcon class="w-3 h-3" />
                                Super Admin
                            </span>
                        </div>

                        <div class="py-1">
                            <MenuItem v-slot="{ active }">
                                <Link 
                                    :href="route('profile.edit')"
                                    :class="[active ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700', 'flex items-center gap-2 px-3 py-2 rounded-lg font-medium transition']"
                                >
                                    <UserCircleIcon class="w-4 h-4" />
                                    <span>الملف الشخصي</span>
                                </Link>
                            </MenuItem>
                            <MenuItem v-slot="{ active }">
                                <Link 
                                    :href="route('settings.index')"
                                    :class="[active ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700', 'flex items-center gap-2 px-3 py-2 rounded-lg font-medium transition']"
                                >
                                    <Cog6ToothIcon class="w-4 h-4" />
                                    <span>إعدادات المؤسسة</span>
                                </Link>
                            </MenuItem>
                            <MenuItem v-if="user?.is_super_admin" v-slot="{ active }">
                                <Link 
                                    :href="route('super-admin.dashboard')"
                                    :class="[active ? 'bg-purple-50 text-purple-700' : 'text-purple-600', 'flex items-center gap-2 px-3 py-2 rounded-lg font-medium transition']"
                                >
                                    <ShieldCheckIcon class="w-4 h-4" />
                                    <span>لوحة تحكم النظام العام</span>
                                </Link>
                            </MenuItem>
                        </div>

                        <div class="pt-1">
                            <MenuItem v-slot="{ active }">
                                <button 
                                    @click="logout"
                                    :class="[active ? 'bg-red-50 text-red-600' : 'text-red-600', 'w-full flex items-center gap-2 px-3 py-2 rounded-lg font-medium transition text-start']"
                                >
                                    <ArrowRightOnRectangleIcon class="w-4 h-4" />
                                    <span>تسجيل الخروج</span>
                                </button>
                            </MenuItem>
                        </div>
                    </MenuItems>
                </transition>
            </Menu>
        </div>
    </header>
</template>

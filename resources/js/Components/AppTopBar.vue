<script setup>
import GlobalSearch from '@/Components/GlobalSearch.vue';
import QuickMenus from '@/Components/QuickMenus.vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import {
    Bars3Icon,
    BellIcon,
    DeviceTabletIcon,
    ChevronDownIcon,
    UserCircleIcon,
    ArrowRightOnRectangleIcon,
    Cog6ToothIcon,
    ShieldCheckIcon,
    GlobeAltIcon
} from '@heroicons/vue/24/outline';
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue';
import { useLanguage } from '@/composables/useLanguage';

// The company, branch and fiscal year are chosen in the sidebar («سياق العمل»).
defineEmits(['toggleSidebar']);

const page = usePage();
const user = page.props.auth?.user;
const { toggleLanguage } = useLanguage();

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <header class="h-16 bg-white border-b border-slate-200 sticky top-0 z-30 px-3 sm:px-4 lg:px-8 flex items-center justify-between gap-2 sm:gap-4 shadow-[0_1px_3px_0_rgba(0,0,0,0.02)]">
        <!-- Start: sidebar toggle, «إضافة» and «عرض», search -->
        <div class="flex items-center gap-1 sm:gap-2 flex-1 min-w-0">
            <button
                @click="$emit('toggleSidebar')"
                type="button"
                class="lg:hidden w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                aria-label="القائمة"
            >
                <Bars3Icon class="w-6 h-6" />
            </button>

            <QuickMenus />
            <!-- Global search palette (Ctrl+K) -->
            <GlobalSearch />
        </div>

        <!-- End: POS, notifications, user menu -->
        <div class="flex items-center gap-1 sm:gap-2 shrink-0">
            <!-- Quick POS Cashier Action Button -->
            <Link
                v-if="$canRoute('pos.index')"
                :href="route('pos.index')"
                class="h-9 inline-flex items-center justify-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-2.5 sm:px-3 rounded-lg shadow-sm transition-colors"
                title="فتح نقطة البيع (الكاشير)"
                data-pos-link
            >
                <DeviceTabletIcon class="w-4 h-4" />
                <span class="hidden xl:inline">نقطة البيع (POS)</span>
            </Link>

            <!-- Notifications Icon -->
            <Link
                :href="route('notifications.index')"
                class="w-9 h-9 flex items-center justify-center text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors relative"
                title="الإشعارات"
            >
                <BellIcon class="w-5 h-5" />
                <span v-if="$page.props.unreadNotifications" class="absolute -top-0.5 -end-0.5 min-w-4 h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full ring-2 ring-white flex items-center justify-center">{{ $page.props.unreadNotifications > 99 ? '99+' : $page.props.unreadNotifications }}</span>
            </Link>

            <!-- User Dropdown Menu -->
            <Menu as="div" class="relative">
                <MenuButton class="h-9 flex items-center gap-2 px-1 sm:ps-1 sm:pe-2 rounded-lg hover:bg-slate-50 transition border border-transparent hover:border-slate-200" data-user-menu>
                    <div class="w-7 h-7 rounded-full bg-gradient-to-tr from-indigo-600 to-indigo-700 text-white flex items-center justify-center font-bold text-xs shadow-sm">
                        {{ user?.name ? user.name.charAt(0) : 'U' }}
                    </div>
                    <div class="hidden xl:flex flex-col text-start leading-tight">
                        <span class="text-xs font-bold text-slate-800">{{ user?.name }}</span>
                        <span class="text-[10px] text-slate-500">{{ user?.is_super_admin ? 'مدير النظام' : 'مدير المؤسسة' }}</span>
                    </div>
                    <ChevronDownIcon class="w-4 h-4 text-slate-400 hidden sm:block" />
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
                            <MenuItem v-slot="{ active }">
                                <button
                                    type="button"
                                    @click="toggleLanguage"
                                    :lang="$page.props.locale === 'ar' ? 'en' : 'ar'"
                                    :class="[active ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700', 'w-full flex items-center gap-2 px-3 py-2 rounded-lg font-medium transition text-start']"
                                    :title="$page.props.locale === 'ar' ? 'Switch to English' : 'التحويل للعربية'"
                                    data-language-toggle
                                >
                                    <GlobeAltIcon class="w-4 h-4" />
                                    <span>{{ $page.props.locale === 'ar' ? 'English' : 'العربية' }}</span>
                                </button>
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

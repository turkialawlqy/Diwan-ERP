<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import BrandLogo from '../Components/BrandLogo.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import { 
    BuildingOfficeIcon, 
    CreditCardIcon, 
    UsersIcon, 
    ArrowRightOnRectangleIcon,
    ShieldCheckIcon
} from '@heroicons/vue/24/outline';

const page = usePage();
const user = page.props.auth?.user;

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-900 flex flex-col font-sans selection:bg-purple-500 selection:text-white">
        <!-- Top Super Admin Navigation Header -->
        <header class="bg-slate-900 text-white h-16 px-4 lg:px-8 flex items-center justify-between border-b border-slate-800 shadow-md">
            <div class="flex items-center gap-6">
                <Link :href="route('super-admin.dashboard')" class="flex items-center gap-2">
                    <BrandLogo size="sm" :white="true" :showSubtitle="false" />
                    <span class="bg-purple-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider">
                        Super Admin
                    </span>
                </Link>

                <nav class="hidden md:flex items-center gap-1 text-xs font-semibold">
                    <Link 
                        :href="route('super-admin.dashboard')" 
                        class="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 text-white hover:bg-slate-700 transition"
                    >
                        <BuildingOfficeIcon class="w-4 h-4 text-purple-400" />
                        <span>جميع الشركات والمنشآت</span>
                    </Link>
                    <Link :href="route('super-admin.plans')" class="px-3 py-2 rounded-lg hover:bg-slate-800 transition">الباقات</Link>
                    <Link :href="route('super-admin.invoices')" class="px-3 py-2 rounded-lg hover:bg-slate-800 transition">فواتير الاشتراكات</Link>
                    <Link :href="route('super-admin.audit')" class="px-3 py-2 rounded-lg hover:bg-slate-800 transition">سجل تدقيق المنصة</Link>
                    <Link :href="route('super-admin.backups')" class="px-3 py-2 rounded-lg hover:bg-slate-800 transition">النسخ الاحتياطي</Link>
                    <Link :href="route('super-admin.legal')" class="px-3 py-2 rounded-lg hover:bg-slate-800 transition">الشروط والخصوصية</Link>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <LanguageSwitcher />

                <div class="flex items-center gap-2 ps-3 border-s border-slate-700">
                    <div class="w-8 h-8 rounded-full bg-purple-600 text-white flex items-center justify-center font-bold text-xs">
                        <ShieldCheckIcon class="w-4 h-4" />
                    </div>
                    <div class="hidden sm:flex flex-col text-start leading-tight">
                        <span class="text-xs font-bold text-slate-100">{{ user?.name }}</span>
                        <span class="text-[10px] text-purple-300">مسؤول النظام الرئيسي</span>
                    </div>

                    <button 
                        @click="logout"
                        title="تسجيل الخروج"
                        class="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition"
                    >
                        <ArrowRightOnRectangleIcon class="w-5 h-5" />
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8">
            <slot />
        </main>
    </div>
</template>

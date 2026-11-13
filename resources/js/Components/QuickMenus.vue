<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowsPointingInIcon, ArrowsPointingOutIcon, ChevronDownIcon, EyeIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { addMenu, viewMenu } from '@/quickMenus';
import { canRoute } from '@/permissions';
import { tx } from '@/i18n';

/** «إضافة» (grouped create shortcuts) and «عرض» (shortcuts to the main lists, and full screen) in the top bar. */
const open = ref(null); // 'add' | 'view' | null
const root = ref(null);

const groups = computed(() => addMenu
    .map((g) => ({ ...g, items: g.items.filter((i) => canRoute(i.route)) }))
    .filter((g) => g.items.length));
const views = computed(() => viewMenu.filter((i) => canRoute(i.route)));

// Full screen, where the browser allows it (not on iPhone).
const canFullscreen = !!document.fullscreenEnabled;
const isFullscreen = ref(!!document.fullscreenElement);
const syncFullscreen = () => { isFullscreen.value = !!document.fullscreenElement; };
const toggleFullscreen = () => {
    open.value = null;
    (document.fullscreenElement ? document.exitFullscreen?.() : document.documentElement.requestFullscreen?.())?.catch(() => {});
};

const toggle = (name) => { open.value = open.value === name ? null : name; };
const outside = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = null; };
const esc = (e) => { if (e.key === 'Escape') open.value = null; };
onMounted(() => { document.addEventListener('mousedown', outside); document.addEventListener('keydown', esc); document.addEventListener('fullscreenchange', syncFullscreen); });
onBeforeUnmount(() => { document.removeEventListener('mousedown', outside); document.removeEventListener('keydown', esc); document.removeEventListener('fullscreenchange', syncFullscreen); });
</script>

<template>
    <div ref="root" class="flex items-center gap-1 sm:gap-1.5 shrink-0" data-quick-menus>
        <div v-if="groups.length" class="sm:relative">
            <button type="button" @click="toggle('add')" class="h-9 inline-flex items-center gap-1.5 px-2.5 sm:px-3 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold" :aria-expanded="open === 'add'" :aria-label="tx('إضافة')" data-quick-add>
                <PlusIcon class="w-4 h-4 stroke-2" /> <span class="hidden sm:inline">{{ tx('إضافة') }}</span> <ChevronDownIcon class="w-3 h-3 hidden sm:block" />
            </button>
            <div v-if="open === 'add'" class="fixed sm:absolute inset-x-3 sm:inset-x-auto top-[4.25rem] sm:top-full sm:start-0 sm:mt-2 sm:w-64 max-h-[75vh] overflow-y-auto bg-white border border-slate-200 rounded-2xl shadow-xl z-50 py-1.5" data-quick-add-panel>
                <div v-for="(g, gi) in groups" :key="g.label" :class="gi ? 'border-t border-slate-100' : ''" class="py-1">
                    <p class="px-4 pt-1.5 pb-1 text-[11px] font-bold text-slate-400">{{ tx(g.label) }}</p>
                    <Link v-for="item in g.items" :key="item.route" :href="route(item.route)" @click="open = null" class="flex items-center justify-between gap-3 px-4 py-2 text-sm text-slate-700 hover:bg-indigo-50 hover:text-indigo-700">
                        <span class="font-semibold">{{ tx(item.label) }}</span>
                        <component :is="item.icon" class="w-4 h-4 text-slate-400" />
                    </Link>
                </div>
            </div>
        </div>

        <div v-if="views.length || canFullscreen" class="sm:relative">
            <button type="button" @click="toggle('view')" class="h-9 inline-flex items-center gap-1.5 px-2.5 sm:px-3 rounded-lg border border-slate-200 bg-white hover:border-indigo-300 text-indigo-700 text-xs font-bold" :aria-expanded="open === 'view'" :aria-label="tx('عرض')" data-quick-view>
                <EyeIcon class="w-4 h-4" /> <span class="hidden sm:inline">{{ tx('عرض') }}</span> <ChevronDownIcon class="w-3 h-3 hidden sm:block" />
            </button>
            <div v-if="open === 'view'" class="fixed sm:absolute inset-x-3 sm:inset-x-auto top-[4.25rem] sm:top-full sm:start-0 sm:mt-2 sm:w-60 max-h-[75vh] overflow-y-auto bg-white border border-slate-200 rounded-2xl shadow-xl z-50 py-1.5" data-quick-view-panel>
                <Link v-for="item in views" :key="item.route" :href="route(item.route)" @click="open = null" class="flex items-center justify-between gap-3 px-4 py-2 text-sm text-indigo-700 hover:bg-indigo-50">
                    <span class="font-semibold">{{ tx(item.label) }}</span>
                    <component :is="item.icon" class="w-4 h-4 text-indigo-400" />
                </Link>
                <div v-if="canFullscreen" :class="views.length ? 'border-t border-slate-100 mt-1.5 pt-1.5' : ''">
                    <button type="button" @click="toggleFullscreen" class="w-full flex items-center justify-between gap-3 px-4 py-2 text-sm text-indigo-700 hover:bg-indigo-50 text-start" data-fullscreen-toggle>
                        <span class="font-semibold">{{ isFullscreen ? tx('الخروج من ملء الشاشة') : tx('ملء الشاشة') }}</span>
                        <component :is="isFullscreen ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="w-4 h-4 text-indigo-400" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

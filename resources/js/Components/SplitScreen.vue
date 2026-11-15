<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ArrowPathIcon, XMarkIcon, ViewColumnsIcon, Bars3BottomLeftIcon, DocumentDuplicateIcon } from '@heroicons/vue/24/outline';
import navigation from '@/navigation';
import { canRoute } from '@/permissions';
import { splitUrl, splitOrientation, splitRatio, closeSplit } from '@/composables/useSplitScreen';

/**
 * The second pane of the split screen: any page of the system (chosen from the same menu the user sees) in a frame next to
 * the current one. The divider is dragged to resize; the pane can sit beside or under the page.
 */
const page = usePage();
const frame = ref(null);
const dragging = ref(false);

const inCountry = (item) => !item.feature || (page.props.features || []).includes(item.feature);
// Every page the user may open from the menu (pages needing an id are not in it).
const pages = computed(() => navigation.flatMap((item) => (item.children
    ? item.children.filter((c) => canRoute(c.route) && inCountry(c)).map((c) => ({ label: c.label, route: c.route, group: item.label }))
    : (canRoute(item.route) ? [{ label: item.label, route: item.route, group: null }] : []))));

const pick = (event) => { if (event.target.value) splitUrl.value = route(event.target.value, undefined, false); event.target.value = ''; };
const reload = () => { try { frame.value?.contentWindow?.location.reload(); } catch { splitUrl.value = splitUrl.value; } };
const sameAsCurrent = () => { splitUrl.value = page.url; };

const start = (event) => {
    dragging.value = true;
    event.target.setPointerCapture?.(event.pointerId);
};
const move = (event) => {
    if (!dragging.value) return;
    const box = event.currentTarget.parentElement.getBoundingClientRect();
    const rtl = document.documentElement.dir === 'rtl';
    let ratio = splitOrientation.value === 'h'
        ? ((rtl ? box.right - event.clientX : event.clientX - box.left) / box.width)
        : ((event.clientY - box.top) / box.height);
    ratio = Math.min(0.8, Math.max(0.2, ratio));
    splitRatio.value = Math.round(ratio * 100) / 100;
};
const stop = () => { dragging.value = false; };
</script>

<template>
    <!-- The divider + second pane (the page itself is the first pane). -->
    <div
        :class="splitOrientation === 'h' ? 'w-1.5 cursor-col-resize hidden lg:block' : 'h-1.5 cursor-row-resize'"
        class="bg-slate-200 hover:bg-indigo-400 flex-shrink-0 touch-none print:hidden"
        data-split-divider
        @pointerdown.prevent="start"
        @pointermove="move"
        @pointerup="stop"
        @pointercancel="stop"
    ></div>
    <section
        :style="splitOrientation === 'h' ? { flexBasis: `${(1 - splitRatio) * 100}%` } : { flexBasis: `${(1 - splitRatio) * 100}%` }"
        class="min-w-0 min-h-0 flex flex-col bg-white border border-slate-200 lg:rounded-xl overflow-hidden print:hidden"
        data-split-pane
    >
        <div class="flex items-center gap-2 px-2 py-1.5 bg-slate-50 border-b border-slate-200 text-xs flex-shrink-0">
            <select class="rounded-lg border-slate-200 text-xs py-1 max-w-[12rem]" data-split-pick @change="pick">
                <option value="">{{ $t('split.open_page') }}</option>
                <option v-for="p in pages" :key="p.route" :value="p.route">{{ $t(p.label) }}</option>
            </select>
            <button type="button" class="p-1.5 rounded-lg hover:bg-slate-200 text-slate-600" :title="$t('split.same_page')" data-split-same @click="sameAsCurrent"><DocumentDuplicateIcon class="w-4 h-4" /></button>
            <button type="button" class="p-1.5 rounded-lg hover:bg-slate-200 text-slate-600" :title="$t('split.reload')" @click="reload"><ArrowPathIcon class="w-4 h-4" /></button>
            <button type="button" class="p-1.5 rounded-lg hover:bg-slate-200 text-slate-600" :title="$t('split.layout')" data-split-layout @click="splitOrientation = splitOrientation === 'h' ? 'v' : 'h'">
                <ViewColumnsIcon v-if="splitOrientation === 'h'" class="w-4 h-4" /><Bars3BottomLeftIcon v-else class="w-4 h-4" />
            </button>
            <span class="flex-1 truncate text-slate-400 font-mono" dir="ltr">{{ splitUrl }}</span>
            <button type="button" class="p-1.5 rounded-lg hover:bg-rose-100 text-slate-600 hover:text-rose-600" :title="$t('split.close')" data-split-close @click="closeSplit"><XMarkIcon class="w-4 h-4" /></button>
        </div>
        <p v-if="!splitUrl" class="flex-1 flex items-center justify-center text-slate-400 text-xs p-6 text-center">{{ $t('split.empty') }}</p>
        <iframe v-else ref="frame" :key="$page.props.locale" :src="splitUrl" class="flex-1 w-full border-0 bg-white" :style="dragging ? 'pointer-events:none' : ''" data-split-frame></iframe>
    </section>
</template>

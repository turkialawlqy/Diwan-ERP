import { ref, watch } from 'vue';

/**
 * Split screen: a second page next to (or under) the current one, shown in a same-origin frame. The state lives in this
 * tab only (sessionStorage), so a refresh keeps the split and another tab starts without one.
 * A page rendered inside the frame knows it with `embedded` and hides the menu and the top bar.
 */
const KEY = 'diwan.split';

const read = () => {
    try { return JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch { return {}; }
};
const saved = read();

export const embedded = typeof window !== 'undefined' && window.self !== window.top;
export const splitOpen = ref(!!saved.open && !embedded);
export const splitUrl = ref(saved.url || '');
export const splitOrientation = ref(saved.orientation === 'v' ? 'v' : 'h'); // h: side by side, v: one above the other
export const splitRatio = ref(Number(saved.ratio) > 0.2 && Number(saved.ratio) < 0.8 ? Number(saved.ratio) : 0.5); // share of the first pane

watch([splitOpen, splitUrl, splitOrientation, splitRatio], () => {
    try { sessionStorage.setItem(KEY, JSON.stringify({ open: splitOpen.value, url: splitUrl.value, orientation: splitOrientation.value, ratio: splitRatio.value })); } catch { /* private mode: not remembered */ }
});

/** Open the split with a page (a path on this site); a link to another site is refused. */
export const openSplit = (url) => {
    if (embedded) return;
    try {
        const target = new URL(url, window.location.origin);
        if (target.origin !== window.location.origin) return;
        splitUrl.value = target.pathname + target.search;
    } catch { return; }
    splitOpen.value = true;
};
export const closeSplit = () => { splitOpen.value = false; };
export const toggleSplit = () => { splitOpen.value = !splitOpen.value; };

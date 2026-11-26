import { ref } from 'vue';

/**
 * Full screen for the whole app: the top bar's button, where the browser allows it (not on iPhone). The state follows
 * the browser, so leaving with Esc or the browser's own control also resets the button. F11 is the browser's own full
 * screen and is not reported to the page.
 */
const doc = typeof document === 'undefined' ? null : document;

export const canFullscreen = !!doc?.fullscreenEnabled;
export const isFullscreen = ref(!!doc?.fullscreenElement);

if (canFullscreen) {
    doc.addEventListener('fullscreenchange', () => { isFullscreen.value = !!doc.fullscreenElement; });
}

/** Enters full screen or leaves it; a refusal by the browser changes nothing. */
export const toggleFullscreen = () => {
    if (!canFullscreen) return;
    (doc.fullscreenElement ? doc.exitFullscreen?.() : doc.documentElement.requestFullscreen?.())?.catch(() => {});
};

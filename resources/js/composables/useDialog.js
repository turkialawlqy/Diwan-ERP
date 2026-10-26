import { reactive } from 'vue';

/**
 * App-wide replacement for window.prompt / alert / confirm: one dialog
 * (PromptDialog, mounted at the app root) driven by this state. Every
 * helper returns a Promise.
 */
export const dialogState = reactive({
    open: false,
    title: '',
    message: '',
    input: null, // null | 'text' | 'textarea' | 'date' | 'number' | 'password'
    value: '',
    placeholder: '',
    minLength: 0,
    required: false,
    confirmText: 'موافق',
    cancelText: 'إلغاء',
    danger: false,
    alertOnly: false,
    resolve: null,
});

const defaults = { title: '', message: '', input: null, value: '', placeholder: '', minLength: 0, required: false, confirmText: 'موافق', cancelText: 'إلغاء', danger: false, alertOnly: false };

function open(options) {
    // A dialog still open (e.g. a double click) is answered as cancelled.
    dialogState.resolve?.(options.alertOnly ? true : null);

    return new Promise((resolve) => {
        Object.assign(dialogState, defaults, options, { open: true, resolve });
    });
}

export function settle(result) {
    const resolve = dialogState.resolve;
    dialogState.open = false;
    dialogState.resolve = null;
    resolve?.(result);
}

/** Asks for a value; resolves the text entered, or null when cancelled. */
export function ask(message, options = {}) {
    return open({ message, input: options.type || 'text', value: options.default ?? '', ...options });
}

/** Resolves true when confirmed, false otherwise. */
export function confirmAction(message, options = {}) {
    return open({ message, ...options }).then((r) => r === true);
}

/** Informational message with a single button. */
export function notify(message, options = {}) {
    return open({ message, alertOnly: true, title: options.title || 'تنبيه', ...options });
}

export function useDialog() {
    return { ask, confirmAction, notify };
}

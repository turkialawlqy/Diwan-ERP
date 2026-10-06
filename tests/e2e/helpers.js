import { expect } from '@playwright/test';

/**
 * Collects console errors, uncaught exceptions and 5xx responses on a page so
 * a test can assert the screen worked cleanly, not just that it rendered.
 */
export function watchErrors(page) {
    const errors = [];
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error' && !/favicon|net::ERR_/.test(m.text())) errors.push(`console: ${m.text()}`); });
    page.on('response', (r) => { if (r.status() >= 500) errors.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`); });
    return errors;
}

/** Type into a search picker and pick the first result with a real mouse click. */
export async function pickFromLookup(page, input, term) {
    await input.click();
    await input.pressSequentially(term, { delay: 30 });
    const option = page.locator('div.absolute button').filter({ hasText: term }).first();
    await expect(option).toBeVisible();
    const label = (await option.innerText()).trim();
    await option.click();
    return label;
}

/** Inertia flash/validation messages rendered anywhere on the page. */
export const pageText = (page) => page.locator('body').innerText();

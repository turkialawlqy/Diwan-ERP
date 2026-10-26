import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { watchErrors } from './helpers';

/*
 * Opens every parameterless screen of the company app, plus the first record
 * linked from each list, and fails on any 5xx, JavaScript exception or console
 * error. Catches broken pages the PHP tests cannot see (missing components,
 * bad props, template errors).
 */
const SKIP = /(\{|^api|^sanctum|export|download|pdf|print|logout|csv|xml|imperson|super-admin|admin\/|lookup|files|^up$|storage|company\/logo|login|register|password|verify|confirm|_ignition|^\/$|backup|stop-impersonating|^auth\/|search)/;

const uris = JSON.parse(execSync('php artisan route:list --json --method=GET', { encoding: 'utf8' }))
    .map((r) => r.uri)
    .filter((u) => !SKIP.test(u));

test('every screen opens without errors', async ({ page }) => {
    test.setTimeout(10 * 60_000);
    const errors = watchErrors(page);
    const failures = [];
    const details = new Set();

    const visit = async (path) => {
        const before = errors.length;
        const response = await page.goto(path, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => `navigation failed: ${e.message}` }));
        const status = response?.status();
        if (typeof status !== 'number' || status >= 400) failures.push(`${path} → ${status}`);
        errors.slice(before).forEach((e) => failures.push(`${path} → ${e}`));
    };

    for (const uri of uris) {
        await visit(`/${uri}`);
        // First record linked from a list screen (e.g. /sales/invoices/12).
        const href = await page.locator(`a[href*="/${uri}/"]`).evaluateAll((as, base) => as
            .map((a) => new URL(a.href).pathname)
            .find((p) => new RegExp(`^/${base}/\\d+$`).test(p)), uri).catch(() => null);
        if (href) details.add(href);
    }
    for (const path of details) await visit(path);

    console.log(`checked ${uris.length} screens and ${details.size} record pages`);
    expect(failures, failures.join('\n')).toEqual([]);
});

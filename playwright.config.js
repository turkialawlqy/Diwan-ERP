import { defineConfig, devices } from '@playwright/test';

/*
 * Browser tests: real clicks against a real server, on a throwaway SQLite
 * database (database/e2e.sqlite) rebuilt from the seeders on every run. The
 * development database is never touched.
 *
 *   npm run test:e2e
 *
 * PLAYWRIGHT_CHROMIUM_PATH points at a preinstalled Chromium when the
 * Playwright-managed browsers are not installed (`npx playwright install chromium`).
 */
const PORT = Number(process.env.E2E_PORT || 8077);
const TENANT = 'anas';
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined;

export const BASE_URL = `http://${TENANT}.localhost:${PORT}`;

export default defineConfig({
    testDir: 'tests/e2e',
    globalSetup: './tests/e2e/global-setup.js',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
    use: {
        baseURL: BASE_URL,
        locale: 'ar-SA',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: {
            executablePath,
            // *.localhost subdomains resolve to this machine.
            args: [`--host-resolver-rules=MAP ${TENANT}.localhost 127.0.0.1`],
        },
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.js/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, storageState: 'tests/e2e/.auth/user.json' },
            dependencies: ['setup'],
        },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT}`,
        url: `http://127.0.0.1:${PORT}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`,
            APP_ENV: 'local',
            APP_DEBUG: 'true',
            APP_LOCALE: 'ar',
            CACHE_STORE: 'array',
            QUEUE_CONNECTION: 'sync',
            SESSION_DRIVER: 'file',
            MAIL_MAILER: 'array',
        },
    },
});

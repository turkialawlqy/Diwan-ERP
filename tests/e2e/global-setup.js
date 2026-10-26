import { execSync } from 'node:child_process';
import { rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';

// Rebuild the throwaway e2e database from migrations + demo seeders.
export default function globalSetup() {
    const db = path.resolve('database/e2e.sqlite');
    rmSync(db, { force: true });
    writeFileSync(db, '');
    execSync('php artisan migrate --seed --force', {
        stdio: 'inherit',
        env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: db, APP_ENV: 'local', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array' },
    });
}

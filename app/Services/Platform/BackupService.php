<?php

namespace App\Services\Platform;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Encrypted backups of the database and uploaded files, retention, and a
 * restore test. Archive layout: manifest.json, database.sql|database.sqlite,
 * files/... (storage/app/public and storage/app/private).
 */
class BackupService
{
    public const PREFIX = 'alostaz-backup-';

    public function create(): BackupRun
    {
        $started = microtime(true);
        $work = $this->workDir();

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $dumpName = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
            // Counted before the dump: a live system only adds rows meanwhile,
            // so a good restore has at least these counts.
            $manifest = [
                'app' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'driver' => $driver,
                'database' => $dumpName,
                'tables' => $this->rowCounts(),
            ];
            $this->dumpDatabase($driver, "{$work}/{$dumpName}");

            $name = self::PREFIX.now()->format('Y-m-d-His').'.zip';
            $zipPath = "{$work}/{$name}";
            $this->zip($zipPath, $work, $dumpName, $manifest);

            $disk = Storage::disk(config('backup.disk'));
            $stream = fopen($zipPath, 'rb');
            $disk->writeStream($name, $stream);
            is_resource($stream) && fclose($stream);

            $run = BackupRun::create([
                'type' => 'backup', 'file' => $name, 'size' => filesize($zipPath), 'status' => 'ok',
                'details' => ['tables' => count($manifest['tables']), 'rows' => array_sum($manifest['tables']), 'encrypted' => (bool) config('backup.password'), 'pruned' => $this->prune()],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            $run = $this->failed('backup', $e, $started);
        } finally {
            File::deleteDirectory($work);
        }

        return $run;
    }

    /**
     * Restores the newest (or given) backup and checks it against its manifest:
     * into BACKUP_VERIFY_DATABASE when configured, otherwise by checking that
     * the dump contains every table (SQLite archives are opened directly).
     */
    public function verify(?string $file = null): BackupRun
    {
        $started = microtime(true);
        $work = $this->workDir();

        try {
            $disk = Storage::disk(config('backup.disk'));
            $file ??= collect($disk->files())->filter(fn ($f) => str_starts_with($f, self::PREFIX))->sort()->last();
            if (! $file) {
                throw new RuntimeException('لا توجد نسخ احتياطية للتحقق منها.');
            }

            $local = "{$work}/".basename($file);
            file_put_contents($local, $disk->readStream($file));
            $zip = new ZipArchive;
            if ($zip->open($local) !== true) {
                throw new RuntimeException('تعذّر فتح الأرشيف.');
            }
            if ($password = config('backup.password')) {
                $zip->setPassword($password);
            }
            if (! $zip->extractTo("{$work}/x")) {
                throw new RuntimeException('تعذّر فك الأرشيف (كلمة مرور النسخ الاحتياطي غير صحيحة؟).');
            }
            $zip->close();

            $manifest = json_decode(file_get_contents("{$work}/x/manifest.json"), true);
            $checked = $this->checkRestore("{$work}/x/{$manifest['database']}", $manifest);

            $run = BackupRun::create([
                'type' => 'verify', 'file' => $file, 'size' => filesize($local), 'status' => 'ok',
                'details' => $checked + ['backup_created_at' => $manifest['created_at']],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            $run = $this->failed('verify', $e, $started, $file ?? null);
        } finally {
            File::deleteDirectory($work);
        }

        return $run;
    }

    /** Keeps the newest daily / weekly (Sunday) / monthly (1st) archives. */
    public function prune(): int
    {
        $disk = Storage::disk(config('backup.disk'));
        $files = collect($disk->files())->filter(fn ($f) => str_starts_with($f, self::PREFIX))->sortDesc()->values();
        $dated = $files->mapWithKeys(fn ($f) => [$f => $this->dateOf($f)])->filter();

        $keep = collect()
            ->merge($dated->unique(fn ($d) => $d->toDateString())->take(config('backup.keep.daily'))->keys())
            ->merge($dated->filter(fn ($d) => $d->isSunday())->unique(fn ($d) => $d->toDateString())->take(config('backup.keep.weekly'))->keys())
            ->merge($dated->filter(fn ($d) => $d->day === 1)->unique(fn ($d) => $d->format('Y-m'))->take(config('backup.keep.monthly'))->keys())
            ->unique();

        $delete = $dated->keys()->diff($keep);
        $delete->each(fn ($f) => $disk->delete($f));

        return $delete->count();
    }

    protected function dumpDatabase(string $driver, string $target): void
    {
        $config = DB::connection()->getConfig();

        if ($driver === 'sqlite') {
            // VACUUM INTO gives a consistent copy even while the app is writing;
            // it is not allowed inside a transaction, so copy table by table then.
            DB::transactionLevel() === 0 ? DB::statement('VACUUM INTO ?', [$target]) : $this->copySqlite($target);

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported database driver for backups: {$driver}");
        }

        $result = Process::timeout(3600)->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->run([
            config('backup.mysqldump'),
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4',
            '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 3306), '--user='.($config['username'] ?? 'root'),
            '--result-file='.$target,
            $config['database'],
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }
    }

    protected function copySqlite(string $target): void
    {
        $out = new \PDO('sqlite:'.$target);
        $out->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $out->beginTransaction();
        $objects = DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type = 'table' DESC");
        foreach ($objects as $object) {
            $out->exec($object->sql);
            if ($object->type !== 'table') {
                continue;
            }
            foreach (DB::table($object->name)->cursor() as $row) {
                $row = (array) $row;
                $columns = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', array_keys($row)));
                $out->prepare('INSERT INTO "'.str_replace('"', '""', $object->name).'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')')
                    ->execute(array_values($row));
            }
        }
        $out->commit();
    }

    protected function zip(string $zipPath, string $work, string $dumpName, array $manifest): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the backup archive.');
        }
        $password = config('backup.password');
        if ($password) {
            $zip->setPassword($password);
        }

        $entries = ['manifest.json' => null, $dumpName => "{$work}/{$dumpName}"];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile("{$work}/{$dumpName}", $dumpName);

        foreach (['public' => storage_path('app/public'), 'private' => storage_path('app/private')] as $label => $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                $entry = "files/{$label}/".str_replace('\\', '/', $file->getRelativePathname());
                $zip->addFile($file->getRealPath(), $entry);
                $entries[$entry] = true;
            }
        }

        if ($password) {
            foreach (array_keys($entries) as $entry) {
                $zip->setEncryptionName($entry, ZipArchive::EM_AES_256);
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('Writing the backup archive failed.');
        }
    }

    protected function checkRestore(string $dump, array $manifest): array
    {
        $expected = $manifest['tables'];

        if ($manifest['driver'] === 'sqlite') {
            $pdo = new \PDO('sqlite:'.$dump);
            $missing = [];
            $mismatch = [];
            foreach ($expected as $table => $rows) {
                try {
                    $count = (int) $pdo->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'"')->fetchColumn();
                    if ($count < (int) $rows && ! $this->volatile($table)) {
                        $mismatch[$table] = [$rows, $count];
                    }
                } catch (Throwable) {
                    $missing[] = $table;
                }
            }
            $this->assertClean($missing, $mismatch);

            return ['method' => 'sqlite-open', 'tables' => count($expected), 'rows' => array_sum($expected)];
        }

        if ($database = config('backup.verify_database')) {
            $config = DB::connection()->getConfig();
            $env = ['MYSQL_PWD' => (string) ($config['password'] ?? '')];
            $base = [config('backup.mysql'), '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 3306), '--user='.($config['username'] ?? 'root')];
            Process::env($env)->run([...$base, '-e', "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}` CHARACTER SET utf8mb4"])->throw();
            Process::timeout(3600)->env($env)->input(fopen($dump, 'rb'))->run([...$base, $database])->throw();

            $missing = [];
            $mismatch = [];
            foreach ($expected as $table => $rows) {
                $out = Process::env($env)->run([...$base, '-N', '-B', '-e', "SELECT COUNT(*) FROM `{$database}`.`{$table}`"]);
                if (! $out->successful()) {
                    $missing[] = $table;
                } elseif ((int) trim($out->output()) < (int) $rows && ! $this->volatile($table)) {
                    $mismatch[$table] = [$rows, (int) trim($out->output())];
                }
            }
            Process::env($env)->run([...$base, '-e', "DROP DATABASE IF EXISTS `{$database}`"]);
            $this->assertClean($missing, $mismatch);

            return ['method' => 'mysql-restore', 'tables' => count($expected), 'rows' => array_sum($expected)];
        }

        // No scratch database: every table must be created in the dump.
        $sql = file_get_contents($dump);
        $missing = array_values(array_filter(array_keys($expected), fn ($t) => ! str_contains($sql, "CREATE TABLE `{$t}`")));
        $this->assertClean($missing, []);

        return ['method' => 'dump-structure', 'tables' => count($expected), 'rows' => array_sum($expected)];
    }

    protected function assertClean(array $missing, array $mismatch): void
    {
        if ($missing || $mismatch) {
            throw new RuntimeException('النسخة لا تطابق قاعدة البيانات: '.json_encode(['missing' => $missing, 'row_mismatch' => $mismatch], JSON_UNESCAPED_UNICODE));
        }
    }

    /** Tables whose rows legitimately disappear between count and dump. */
    protected function volatile(string $table): bool
    {
        return in_array($table, ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'], true);
    }

    protected function rowCounts(): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn ($t) => str_starts_with($t, 'sqlite_'))
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])
            ->sortKeys()
            ->all();
    }

    protected function failed(string $type, Throwable $e, float $started, ?string $file = null): BackupRun
    {
        Log::critical("Backup {$type} failed: ".$e->getMessage());
        if ($to = config('backup.notify')) {
            try {
                Mail::raw("فشل {$type} للنسخ الاحتياطي في ".config('app.name').":\n\n".$e->getMessage(), fn ($m) => $m->to($to)->subject('تنبيه: فشل النسخ الاحتياطي'));
            } catch (Throwable $mailError) {
                report($mailError);
            }
        }

        return BackupRun::create(['type' => $type, 'file' => $file, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
    }

    protected function dateOf(string $file): ?Carbon
    {
        return preg_match('/(\d{4}-\d{2}-\d{2}-\d{6})/', $file, $m) ? Carbon::createFromFormat('Y-m-d-His', $m[1]) : null;
    }

    protected function workDir(): string
    {
        $dir = storage_path('app/backup-work/'.uniqid('', true));
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }
}

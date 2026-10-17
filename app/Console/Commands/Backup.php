<?php

namespace App\Console\Commands;

use App\Services\Platform\BackupService;
use Illuminate\Console\Command;

class Backup extends Command
{
    protected $signature = 'erp:backup {--verify : Restore-test the newest backup instead of creating one} {--file= : Backup file to verify} {--tag= : Keep this backup outside the daily rotation (e.g. preupdate)}';

    protected $description = 'Create an encrypted backup of the database and uploaded files (or verify one).';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('verify') && ! config('backup.password')) {
            $this->warn('BACKUP_PASSWORD is not set: the archive will NOT be encrypted.');
        }

        $run = $this->option('verify') ? $backups->verify($this->option('file')) : $backups->create($this->option('tag'));

        if ($run->status !== 'ok') {
            $this->error($run->error);

            return self::FAILURE;
        }

        $this->info(($this->option('verify') ? 'Verified ' : 'Created ').$run->file.' ('.number_format(($run->size ?? 0) / 1048576, 2).' MB) '.json_encode($run->details, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

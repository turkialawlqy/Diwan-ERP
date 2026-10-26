<?php

namespace App\Console\Commands;

use App\Services\Platform\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Restores an erp:backup archive over the current database and uploaded
 * files. The site is put in maintenance mode while it runs.
 */
class Restore extends Command
{
    protected $signature = 'erp:restore {file : Archive name on the backup disk (see storage/app/backups)} {--disk= : Disk to read from (default: BACKUP_DISK; e.g. offsite)} {--force : Do not ask for confirmation}';

    protected $description = 'Restore the database and uploaded files from an encrypted backup';

    public function handle(BackupService $backups): int
    {
        $this->warn('سيُستبدل كل ما في قاعدة البيانات والملفات المرفوعة بمحتوى النسخة: '.$this->argument('file'));
        if (! $this->option('force') && ! $this->confirm('هل أنت متأكد؟ خذ نسخة احتياطية من الوضع الحالي أولاً (erp:backup --tag=prerestore).')) {
            return self::FAILURE;
        }

        Artisan::call('down', ['--retry' => 60]);
        try {
            $result = $backups->restore($this->argument('file'), $this->option('disk'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->warn('الموقع ما زال في وضع الصيانة. أصلح السبب ثم أعد المحاولة، أو شغّل php artisan up.');

            return self::FAILURE;
        }

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize:clear');
        Artisan::call('up');

        $this->info("تمت الاستعادة من نسخة {$result['created_at']} ({$result['tables']} جدولاً).");
        if (! $result['key_matches']) {
            $this->error('مفتاح التطبيق في النسخة يختلف عن APP_KEY الحالي. ضع هذه القيمة في .env ثم شغّل php artisan optimize:clear، وإلا تعذّرت قراءة مفاتيح ZATCA والبيانات المشفرة:');
            $this->line('APP_KEY='.$result['app_key']);
        }

        return self::SUCCESS;
    }
}

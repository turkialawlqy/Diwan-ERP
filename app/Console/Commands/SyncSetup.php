<?php

namespace App\Console\Commands;

use App\Services\Sync\HttpSyncTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Sets up how this server takes part in branch synchronisation, by asking: stand-alone, head office, or a branch of one. Run by the
 * installers (and by hand later) so nobody edits .env or remembers the steps. Everything can also be passed as options.
 */
class SyncSetup extends Command
{
    protected $signature = 'sync:setup {--role= : standalone | hub | branch} {--node= : branch number (2 to 99)} {--hub-url= : head office address} {--token= : the branch token from the head office} {--env= : .env file to write (default: the application\'s)} {--skip-check : do not try the head office before saving} {--skip-join : write the settings only, do not install change capture now} {--force-join : join even if a copy of the change feed of another node is here (it is emptied)}';

    protected $description = 'Ask how this server joins branch synchronisation (stand-alone, head office or branch) and set it up';

    public function handle(): int
    {
        $role = $this->option('role') ?: $this->choice('ما دور هذا الخادم؟', ['standalone' => 'مستقل (لا مزامنة، الافتراضي)', 'hub' => 'المكتب الرئيسي (يستقبل الفروع)', 'branch' => 'فرع (يتصل بمكتب رئيسي)'], 'standalone');
        $role = ['standalone' => 'off', 'off' => 'off', 'hub' => 'hub', 'branch' => 'branch'][$role] ?? null;
        if ($role === null) {
            $this->error('الدور: standalone أو hub أو branch.');

            return self::FAILURE;
        }

        $node = $role === 'hub' ? 1 : 0;
        $values = ['SYNC_ROLE' => $role];
        if ($role === 'branch') {
            $node = (int) ($this->option('node') ?: $this->ask('رقم هذا الفرع (من 2 إلى 99، يعطيه لك المكتب الرئيسي ولا يتكرر بين فرعين)'));
            $url = rtrim((string) ($this->option('hub-url') ?: $this->ask('عنوان المكتب الرئيسي، مثل https://erp.example.com')), '/');
            $token = trim((string) ($this->option('token') ?: $this->secret('الرمز الذي أعطاك إياه المكتب الرئيسي')));
            if ($node < 2 || $node > 99 || ! preg_match('#^https?://[^\s]+$#', $url) || $token === '') {
                $this->error('أدخل رقم فرع من 2 إلى 99، وعنوانًا يبدأ بـ http أو https، والرمز.');

                return self::FAILURE;
            }
            if (! $this->option('skip-check') && ! $this->reachable($url, $token)) {
                $this->warn('تعذر الاتصال بالمكتب الرئيسي الآن. تُحفظ الإعدادات، وتبدأ المزامنة حين يتوفر الاتصال.');
            }
            $values += ['SYNC_HUB_URL' => $url, 'SYNC_TOKEN' => $token];
        }
        // The installers read this to set the database's id stepping for the node.
        $values['SYNC_NODE'] = $role === 'off' ? '' : (string) $node;

        $this->writeEnv($values);
        config(['sync-node.role' => $role]);

        if ($role === 'hub') {
            $this->option('skip-join') || Artisan::call('sync:join', ['node' => 1], $this->output);
            $this->newLine();
            $this->info('المكتب الرئيسي جاهز. لإضافة فرع شغّل: php artisan sync:node 2 "اسم الفرع" ثم أعطِ رقم الفرع والرمز لمن يثبّت الفرع.');
            $this->line('يجب أن يكون هذا الخادم متاحًا للفروع عبر HTTPS (الإعدادات ← الوصول من الإنترنت).');
        } elseif ($role === 'branch') {
            $this->option('skip-join') || Artisan::call('sync:join', ['node' => $node, '--force' => (bool) $this->option('force-join')], $this->output);
            $this->newLine();
            $this->info("هذا الخادم فرع رقم {$node}. تبدأ المزامنة تلقائيًا كل دقيقة بعد تشغيل الجدولة.");
            $this->warn('تأكد أن قاعدة البيانات هنا استُعيدت من نسخة احتياطية للمكتب الرئيسي أُخذت قبل تفعيل المزامنة عليه (انظر docs/SYNC-BRANCHES.md).');
            $this->line("ولقاعدة MariaDB: auto_increment_increment=100 و auto_increment_offset={$node}");
        } else {
            $this->info('الخادم مستقل: لا مزامنة.');
        }

        return self::SUCCESS;
    }

    protected function reachable(string $url, string $token): bool
    {
        try {
            $version = trim((string) @file_get_contents(base_path('VERSION')));
            $answer = (new HttpSyncTransport($url, $token))->post('handshake', ['version' => $version]);
            if (! ($answer['ok'] ?? false)) {
                $this->warn('المكتب الرئيسي يعمل بإصدار '.($answer['version'] ?? '?')." وهذا الخادم بإصدار {$version}: حدّث الأقدم منهما.");

                return false;
            }
            $this->info('تم الاتصال بالمكتب الرئيسي والرمز مقبول.');

            return true;
        } catch (\Throwable $e) {
            $this->warn('الاتصال: '.$e->getMessage());

            return false;
        }
    }

    /** @param array<string, string> $values */
    protected function writeEnv(array $values): void
    {
        $file = $this->option('env') ?: base_path('.env');
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        foreach ($values as $key => $value) {
            $line = $key.'='.(preg_match('/^[A-Za-z0-9_.:\/-]*$/', $value) ? $value : '"'.str_replace('"', '\\"', $value).'"');
            $text = preg_match("/^{$key}=.*$/m", $text) ? preg_replace("/^{$key}=.*$/m", str_replace('\\', '\\\\', $line), $text) : rtrim($text, "\r\n")."\n{$line}\n";
        }
        file_put_contents($file, $text);
    }
}

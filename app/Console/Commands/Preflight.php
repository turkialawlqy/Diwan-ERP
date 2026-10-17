<?php

namespace App\Console\Commands;

use App\Services\Platform\HealthCheck;
use Illuminate\Console\Command;

/**
 * Go-live checklist for a production server. Run it after every deploy:
 * it fails on settings that are unsafe or that silently stop automation.
 */
class Preflight extends Command
{
    protected $signature = 'erp:preflight';

    protected $description = 'Check that the production configuration is safe and complete';

    public function handle(HealthCheck $health): int
    {
        $errors = [];
        $warnings = [];
        $production = app()->environment('production');

        $check = function (bool $ok, string $message, bool $blocking = true) use (&$errors, &$warnings) {
            if ($ok) {
                return;
            }
            if ($blocking) {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        };

        $check($production, 'APP_ENV ليس production.');
        $check(! config('app.debug'), 'APP_DEBUG مفعّل: صفحات الأخطاء تكشف الأسرار.');
        $check((bool) config('app.key'), 'APP_KEY غير مضبوط (php artisan key:generate).');
        $check(config('security.force_https') || str_starts_with((string) config('app.url'), 'https://'), 'HTTPS غير مفروض (FORCE_HTTPS).');
        $check((bool) config('session.secure'), 'SESSION_SECURE_COOKIE معطّل.', false);
        $check((bool) config('backup.password'), 'BACKUP_PASSWORD غير مضبوط: النسخ لن تُنشأ في الإنتاج.');
        $check((bool) config('backup.offsite_disk'), 'لا توجد نسخة احتياطية خارج الخادم (BACKUP_OFFSITE_DISK).', false);
        $check(config('queue.default') !== 'sync', 'QUEUE_CONNECTION=sync: الإرسال للهيئة والبريد يعطّل الطلبات.');
        $check(! config('tenancy.allow_dev_override'), 'TENANCY_ALLOW_DEV_OVERRIDE مفعّل.', false);
        $check((bool) config('tenancy.reject_unknown_hosts'), 'TENANCY_REJECT_UNKNOWN_HOSTS معطّل: روابط إعادة كلمة المرور قابلة للتزوير.');
        $check((bool) config('tenancy.enforce_subdomain'), 'TENANCY_ENFORCE_SUBDOMAIN معطّل: كل الشركات على نفس النطاق.', false);
        $check(config('tenancy.central_domain') !== 'localhost', 'APP_DOMAIN غير مضبوط.');
        $check(! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER لا يرسل بريداً فعلياً (log/array): لن يصل التحقق ولا إعادة كلمة المرور.');
        $check(config('app.timezone') === 'Asia/Riyadh', 'APP_TIMEZONE ليس Asia/Riyadh.', false);
        $check(config('logging.default') === 'daily' || in_array('daily', (array) config('logging.channels.stack.channels'), true), 'السجل لا يدور يومياً (LOG_STACK=daily).', false);
        $check(is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')), 'مجلدات storage أو bootstrap/cache غير قابلة للكتابة.');
        $check(app()->configurationIsCached(), 'الإعدادات غير مخزنة مؤقتاً (php artisan optimize).', false);

        foreach ($health->run()['checks'] as $name => $result) {
            $check($result['ok'], "فحص {$name}: {$result['detail']}", $name === 'database');
        }

        foreach ($warnings as $w) {
            $this->warn('تنبيه: '.$w);
        }
        foreach ($errors as $e) {
            $this->error('خطأ: '.$e);
        }

        if ($errors) {
            $this->error(count($errors).' مشكلة تمنع التشغيل الإنتاجي.');

            return self::FAILURE;
        }

        $this->info('الإعدادات جاهزة للإنتاج'.($warnings ? ' (مع '.count($warnings).' تنبيه).' : '.'));

        return self::SUCCESS;
    }
}

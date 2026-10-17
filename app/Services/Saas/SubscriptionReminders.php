<?php

namespace App\Services\Saas;

use App\Models\Company;
use App\Models\User;
use App\Notifications\ErpAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Emails the company owners before the trial or subscription ends (7, 3
 * and 0 days), during the grace period and once it has expired. Each step
 * is sent once per end date.
 */
class SubscriptionReminders
{
    public function __construct(protected PlanGuard $guard) {}

    public function send(Company $company): int
    {
        $trial = $company->status === 'trial';
        $ends = $trial ? $company->trial_ends_at : $company->subscription_ends_at;
        if (! $ends) {
            return 0;
        }

        $days = (int) now()->startOfDay()->diffInDays($ends->copy()->startOfDay(), false);
        $grace = (int) config('saas.grace_days', 7);
        $what = $trial ? 'الفترة التجريبية' : 'اشتراكك';

        [$step, $title, $body, $level] = match (true) {
            in_array($days, config('saas.reminder_days', [7, 3, 0]), true) => ["before_{$days}",
                $days === 0 ? "تنتهي {$what} اليوم" : "تنتهي {$what} خلال {$days} أيام",
                "تنتهي {$what} في {$ends->format('Y-m-d')}. جدّد الاشتراك من صفحة الاشتراك حتى يستمر العمل دون انقطاع.", $days === 0 ? 'danger' : 'warning'],
            $days === -1 => ['grace', "انتهت {$what}: فترة سماح {$grace} أيام",
                "انتهت {$what}. يعمل النظام كالمعتاد خلال فترة السماح، ثم يصبح للقراءة فقط حتى التجديد.", 'danger'],
            $days === -$grace - 1 => ['expired', 'أصبح النظام للقراءة فقط',
                'انتهت فترة السماح، ولا يمكن إضافة مستندات جديدة حتى تجديد الاشتراك. بياناتك محفوظة كاملة.', 'danger'],
            default => [null, null, null, null],
        };
        if (! $step) {
            return 0;
        }

        $key = "subscription-reminder:{$company->id}:{$step}:{$ends->toDateString()}";
        if (! Cache::add($key, true, now()->addDays(60))) {
            return 0; // already sent
        }

        $owners = User::where('company_id', $company->id)->where('status', 'active')->get()->filter(fn (User $u) => $u->isCompanyOwner());
        foreach ($owners as $owner) {
            $owner->notify(new ErpAlert('subscription_'.$step, $title, $body, route('billing.index', [], false), $level));
        }

        return $owners->count();
    }
}

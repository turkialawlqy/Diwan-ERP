<?php

namespace App\Http\Controllers;

use App\Services\Messaging\CompanyMessaging;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

/** «الإعدادات ← الرسائل»: the company's own SMTP server and SMS / WhatsApp gateways, with a test send. */
class MessagingSettingsController extends Controller
{
    public function __construct(protected CompanyMessaging $messaging) {}

    protected function company()
    {
        return app(TenantManager::class)->getCompany();
    }

    public function edit()
    {
        return Inertia::render('Settings/Messaging', ['values' => $this->messaging->values($this->company())]);
    }

    public function update(Request $request)
    {
        $v = $request->validate([
            'mail_host' => 'nullable|string|max:190', 'mail_port' => 'nullable|integer|min:1|max:65535', 'mail_username' => 'nullable|string|max:190', 'mail_password' => 'nullable|string|max:190',
            'mail_encryption' => 'nullable|in:,tls,ssl', 'mail_from_address' => 'nullable|email|max:190', 'mail_from_name' => 'nullable|string|max:120',
            'sms_url' => 'nullable|url:https|max:500', 'sms_token' => 'nullable|string|max:500', 'sms_sender' => 'nullable|string|max:60', 'sms_body' => 'nullable|string|max:2000',
            'whatsapp_url' => 'nullable|url:https|max:500', 'whatsapp_token' => 'nullable|string|max:500', 'whatsapp_body' => 'nullable|string|max:2000',
        ]);
        foreach (['sms_body', 'whatsapp_body'] as $key) {
            if (! empty($v[$key]) && ! is_array(json_decode(strtr($v[$key], ['{to}' => 'x', '{message}' => 'x', '{sender}' => 'x']), true))) {
                return back()->withErrors([$key => 'القالب ليس JSON صحيحًا.']);
            }
        }
        $this->messaging->save($this->company(), $v);

        return back()->with('success', 'حُفظت إعدادات الرسائل');
    }

    /** Sends a test message through the saved settings and reports what the server answered. */
    public function test(Request $request)
    {
        $v = $request->validate(['channel' => 'required|in:email,sms,whatsapp', 'to' => 'required|string|max:190']);
        $company = $this->company();

        if ($v['channel'] === 'email') {
            $request->validate(['to' => 'email']);
            $mailer = $this->messaging->mailer($company);
            try {
                Mail::mailer($mailer ?? config('mail.default'))->raw('هذه رسالة تجربة من إعدادات البريد في '.$company->name, fn ($m) => $m->to($v['to'])->subject('رسالة تجربة — '.$company->name));
            } catch (\Throwable $e) {
                return back()->withErrors(['test' => 'تعذّر الإرسال: '.mb_substr($e->getMessage(), 0, 200)]);
            }

            return back()->with('success', 'أُرسلت رسالة التجربة إلى '.$v['to']);
        }

        $result = $this->messaging->send($company, $v['channel'], $v['to'], 'رسالة تجربة من '.$company->name);

        return $result['ok'] ? back()->with('success', 'أُرسلت رسالة التجربة (استجابة '.$result['status'].')') : back()->withErrors(['test' => 'فشل الإرسال: '.($result['error'] ?? 'غير معروف')]);
    }
}

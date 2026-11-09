<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a customer's contact that a portal account was opened for him; sent by the queue once the account is saved. */
class PortalInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تمت دعوتك إلى بوابة العملاء')
            ->greeting('مرحبًا '.$notifiable->name)
            ->line('فُتح لك حساب في بوابة العملاء لمتابعة فواتيرك وتذاكرك وعقودك.')
            ->line('تدخل بهذا البريد الإلكتروني، ويصلك رمز دخول في كل مرة بلا كلمة مرور.')
            ->action('فتح البوابة', $notifiable->loginUrl());
    }
}

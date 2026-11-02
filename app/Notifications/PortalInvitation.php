<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a customer's contact that a portal account was opened for him. */
class PortalInvitation extends Notification
{
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

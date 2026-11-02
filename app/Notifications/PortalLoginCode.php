<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The one-time sign-in code for the customer portal (sent at once, never queued with the code in a job log). */
class PortalLoginCode extends Notification
{
    public function __construct(protected string $code, protected int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رمز الدخول إلى بوابة العملاء')
            ->greeting('مرحبًا '.$notifiable->name)
            ->line('رمز الدخول الخاص بك:')
            ->line('**'.$this->code.'**')
            ->line("صالح لمدة {$this->minutes} دقائق ويُستعمل مرة واحدة. إن لم تطلبه فتجاهل هذه الرسالة.");
    }
}

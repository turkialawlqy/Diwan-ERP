<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A staff reply on a ticket the customer opened (so he knows to come back to the portal). */
class PortalTicketReply extends Notification
{
    public function __construct(protected string $number, protected string $subject, protected string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("رد جديد على تذكرتك {$this->number}")
            ->greeting('مرحبًا '.$notifiable->name)
            ->line("وصلك رد على التذكرة: {$this->subject}")
            ->action('فتح التذكرة', $this->url);
    }
}

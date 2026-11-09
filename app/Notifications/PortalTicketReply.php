<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A staff reply on a ticket the customer opened (so he knows to come back to the portal); sent by the queue. */
class PortalTicketReply extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $number, protected string $subject, protected string $url)
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
            ->subject("رد جديد على تذكرتك {$this->number}")
            ->greeting('مرحبًا '.$notifiable->name)
            ->line("وصلك رد على التذكرة: {$this->subject}")
            ->action('فتح التذكرة', $this->url);
    }
}

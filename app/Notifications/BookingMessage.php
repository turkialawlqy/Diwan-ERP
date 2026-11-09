<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A mail to the customer about a booking (confirmation, reminder, cancellation), sent by the queue once the booking is saved. */
class BookingMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $subject, protected array $lines, protected ?string $url = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $this->url ? $mail->action('عرض الحجز', $this->url) : $mail;
    }
}

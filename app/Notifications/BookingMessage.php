<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A mail to the customer about a booking (confirmation, reminder, cancellation). */
class BookingMessage extends Notification
{
    public function __construct(protected string $subject, protected array $lines, protected ?string $url = null) {}

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

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A business alert (overdue invoices, expiring documents, pending approvals...)
 * delivered in-app and, when mail is configured, by e-mail.
 */
class ErpAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $title, public string $body, public ?string $url = null, public string $level = 'info') {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && config('mail.default') !== 'log' && ($notifiable->notify_by_email ?? true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->body);

        return $this->url ? $mail->action('فتح في النظام', url($this->url)) : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'level' => $this->level];
    }
}

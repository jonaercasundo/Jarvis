<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ImportantEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly array $email,
        private readonly string $reason,
    ) {}

    /**
     * Channels are configurable in config/jarvis.php — defaults to
     * database + broadcast. Add 'mail' there to also get an email.
     */
    public function via(object $notifiable): array
    {
        return config('jarvis.notification_channels', ['database']);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'uid' => $this->email['uid'] ?? null,
            'from' => $this->email['from'] ?? null,
            'subject' => $this->email['subject'] ?? null,
            'reason' => $this->reason,
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Important email: '.($this->email['subject'] ?? '(no subject)'))
            ->line('JC flagged an incoming email as important.')
            ->line('From: '.($this->email['from'] ?? 'unknown'))
            ->line('Why: '.$this->reason);
    }
}

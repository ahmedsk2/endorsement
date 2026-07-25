<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm your email address" — used for BOTH a pending registration and an existing
 * account. The link is a Laravel SIGNED URL (HMAC over the route with an expiry), so no
 * token needs storing and a tampered or stale link is rejected by the framework.
 */
class VerifyEmailAddress extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $url,
        private readonly ?string $name = null,
        private readonly bool $isRegistration = true,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Confirm your email address')
            ->greeting($this->name !== null && $this->name !== '' ? "Hello {$this->name}," : 'Hello,');

        if ($this->isRegistration) {
            $message->line('Somebody registered for the Paediatric Endorsement system with this email address.')
                ->line('Confirm the address so an administrator can review and activate the account.');
        } else {
            $message->line('Confirm this email address for your Paediatric Endorsement account.')
                ->line('It is used for password resets and, if you choose it, your sign-in codes.');
        }

        return $message
            ->action('Confirm email address', $this->url)
            ->line('The link expires in 48 hours.')
            ->line('If this was not you, ignore this email — nothing happens until the link is used.');
    }
}

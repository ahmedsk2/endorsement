<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail second-factor code. Carries the code and nothing else — no patient data, no
 * account identifiers beyond the recipient's own name.
 */
class LoginOtpCode extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly ?string $name = null,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your sign-in code')
            ->greeting($this->name !== null && $this->name !== '' ? "Hello {$this->name}," : 'Hello,')
            ->line('Your one-time sign-in code for the Paediatric Endorsement system is:')
            ->line('**'.$this->code.'**')
            ->line('It expires in '.\App\Support\EmailOtp::LIFETIME_MINUTES.' minutes and can be used once.')
            ->line('If you did not try to sign in, ignore this email and tell your administrator — somebody knows your password.');
    }
}

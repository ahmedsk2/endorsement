<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation itself: one link, to one address, that creates one account.
 *
 * The link IS the credential, so this message is the only place it travels and it goes to
 * exactly the address the account will belong to. Nothing else is in here — no role, no
 * inviter, no unit — because an invitation that has gone astray should tell its finder as
 * little as possible about the institution it came from.
 */
class InvitationMail extends Mailable
{
    public function __construct(
        public string $link,
        public \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your access to the Paediatric Endorsement system');
    }

    public function content(): Content
    {
        $body = "You have been invited to create an account on the paediatric shift-handover system.\n\n"
            ."Open this link to choose a username and password:\n\n"
            .$this->link."\n\n"
            .'The link works once and expires on '.$this->expiresAt->format('D j M Y, H:i')
            .' ('.config('app.timezone').")."
            ."\n\nIf you were not expecting this, ignore it — no account exists until the link is used.";

        return new Content(text: 'mail.ops-alert', with: ['body' => $body]);
    }
}

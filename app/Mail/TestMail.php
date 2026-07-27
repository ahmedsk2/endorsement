<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One message, sent on demand from Admin → Settings, to prove SMTP works.
 *
 * It exists because a saved mail configuration is not evidence of a working one: until
 * 2026-07-27 filling in those fields did not even select the smtp transport, so every
 * message went to a log file while the screen said "Settings saved."
 *
 * Deliberately dull, and PHI-free like every other message this system sends: a timestamp,
 * the host, and a sentence saying what its arrival proves.
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Endorsement] Test message');
    }

    public function content(): Content
    {
        $body = "This is a test message from the Paediatric Endorsement system.\n\n"
            ."If you are reading it, SMTP is working — invitations, password resets and "
            ."operational alerts can all be delivered.\n\n"
            .'Sent: '.now()->toDateTimeString().' ('.config('app.timezone').")\n"
            .'Host: '.config('app.url')."\n\n"
            .'This message contains no patient information.';

        return new Content(text: 'mail.ops-alert', with: ['body' => $body]);
    }
}

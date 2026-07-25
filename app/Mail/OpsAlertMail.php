<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An operational alert: a scheduled control failed and a human needs to know tonight.
 *
 * Plain text, and deliberately CONTENT-FREE about patients. It travels through an external
 * mail relay and lands in a mailbox with none of this system's access controls around it,
 * so it carries a job name, a timestamp and a hostname — never a row, a name or an MRN.
 * Whoever receives it goes to the server for detail.
 */
class OpsAlertMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $detail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Endorsement] '.$this->subjectLine);
    }

    public function content(): Content
    {
        $body = trim($this->subjectLine."\n\n".$this->detail)."\n\n"
            .'Host: '.config('app.url')."\n"
            .'Time: '.now()->toDateTimeString().' ('.config('app.timezone').")\n\n"
            .'This is an automated operational alert. It deliberately contains no patient '
            .'information — check the server for detail.';

        return new Content(text: 'mail.ops-alert', with: ['body' => $body]);
    }
}

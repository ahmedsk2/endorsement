<?php

namespace App\Support\Push;

use App\Models\PushSubscription;

/**
 * The seam between the reminder command and the wire. Payloads are small arrays
 * (title/body/url) composed ONLY from unit codes and dates — never patient data.
 */
interface PushSender
{
    /**
     * @param array{title: string, body: string, url: string} $payload
     */
    public function send(PushSubscription $subscription, array $payload): void;
}

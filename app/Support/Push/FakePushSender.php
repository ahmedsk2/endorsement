<?php

namespace App\Support\Push;

use App\Models\PushSubscription;

/**
 * Test double: records every (subscription, payload) pair instead of touching the wire.
 */
class FakePushSender implements PushSender
{
    /** @var list<array{0: PushSubscription, 1: array{title: string, body: string, url: string}}> */
    public array $sent = [];

    public function send(PushSubscription $subscription, array $payload): void
    {
        $this->sent[] = [$subscription, $payload];
    }
}

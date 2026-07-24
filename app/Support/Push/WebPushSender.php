<?php

namespace App\Support\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * The real wire. VAPID keys are owner-managed env values (never committed); with no keys
 * configured, sends are skipped with a log line rather than failing the scheduler.
 * A permanently-gone endpoint (404/410) prunes its subscription row.
 */
class WebPushSender implements PushSender
{
    private ?WebPush $client = null;

    private function client(): ?WebPush
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $public = config('endorsement.vapid.public_key');
        $private = config('endorsement.vapid.private_key');

        if (! $public || ! $private) {
            return null;
        }

        return $this->client = new WebPush([
            'VAPID' => [
                'subject' => config('endorsement.vapid.subject'),
                'publicKey' => $public,
                'privateKey' => $private,
            ],
        ]);
    }

    public function send(PushSubscription $subscription, array $payload): void
    {
        $client = $this->client();

        if ($client === null) {
            Log::info('endorsement:remind skipped a push — VAPID keys are not configured.');

            return;
        }

        $report = $client->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
            ]),
            json_encode($payload, JSON_UNESCAPED_SLASHES),
        );

        if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
            $subscription->delete();
        }
    }
}

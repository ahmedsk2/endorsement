<?php

namespace App\Http\Controllers;

use App\Rules\PushServiceEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Register / remove THIS device's web-push subscription for the signed-in user.
 * Infrastructure only: endpoints and keys, no clinical data.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', new PushServiceEndpoint()],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
            ],
        );

        return back()->with('status', 'Notifications enabled on this device.');
    }

    /**
     * Push one test notification to every device this user has registered.
     *
     * Without this, the only way to know push works is to wait for a real handover reminder
     * — which fires at 07:30 or 15:30, only for units you have opted into, only if that
     * unit's handover is still unsigned, and only once per unit per slot. That is a poor
     * way to find out that the VAPID keys were pasted with a trailing space.
     *
     * Same payload SHAPE as a real reminder, and the same rule about its contents: a title,
     * a line of text and a URL, all of them composed here, none of them from any record.
     */
    public function test(Request $request, \App\Support\Push\PushSender $sender): RedirectResponse
    {
        $subscriptions = $request->user()->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return back()->with('push_test', [
                'ok' => false,
                'message' => 'This device is not registered yet — use "Enable notifications on this device" first.',
            ]);
        }

        if (config('endorsement.vapid.public_key') === null || config('endorsement.vapid.private_key') === null) {
            return back()->with('push_test', [
                'ok' => false,
                'message' => 'Push is not configured on the server — an administrator sets the VAPID keys in Admin → Settings.',
            ]);
        }

        $delivered = 0;
        $failure = null;

        foreach ($subscriptions as $subscription) {
            try {
                $sender->send($subscription, [
                    'title' => 'Endorsement test',
                    'body' => 'Notifications are working on this device.',
                    'url' => '/endorsement',
                ]);
                $delivered++;
            } catch (\Throwable $e) {
                // The push service's own words. "410 Gone" means the browser dropped the
                // subscription; a VAPID complaint means the keys are wrong. Those need
                // different fixes, so a generic failure would be no help at all.
                $failure ??= $e->getMessage();
            }
        }

        return back()->with('push_test', $delivered > 0
            ? [
                'ok' => true,
                'message' => 'Sent to '.$delivered.' registered device'.($delivered === 1 ? '' : 's')
                    .'. If nothing appears, check notifications are allowed for this site'
                    .' — and on an iPhone, that it was added to the Home Screen.',
            ]
            : ['ok' => false, 'message' => 'Could not send: '.($failure ?? 'no device accepted the notification.')]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', new PushServiceEndpoint()],
        ]);

        $request->user()->pushSubscriptions()
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return back()->with('status', 'Notifications disabled on this device.');
    }
}

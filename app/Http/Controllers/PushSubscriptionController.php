<?php

namespace App\Http\Controllers;

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
            'endpoint' => ['required', 'string', 'max:2000'],
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

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        $request->user()->pushSubscriptions()
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return back()->with('status', 'Notifications disabled on this device.');
    }
}

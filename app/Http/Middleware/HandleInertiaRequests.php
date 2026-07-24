<?php

namespace App\Http\Middleware;

use App\Support\AccessControl;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Identity + effective capability KEYS. The Vue nav hides items via `auth.can`
            // (cosmetic only — the server-side `cap:` gate remains the real authority). A guest
            // sees a null user and an empty capability set.
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'member_name' => $user->member_name,
                    'full_name' => $user->full_name,
                    'position' => $user->position,
                ] : null,
                'can' => $user ? AccessControl::capabilitiesFor($user) : [],
            ],
            // One-shot flash surface for the layout's status/error banners.
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}

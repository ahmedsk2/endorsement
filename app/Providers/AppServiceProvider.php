<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AccessControl;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The push seam: tests swap in a fake; production talks VAPID web-push.
        $this->app->bind(
            \App\Support\Push\PushSender::class,
            \App\Support\Push\WebPushSender::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A brake on bulk reads of patient data: a legitimate clinician opens a handful of
        // sheets a shift; a scripted scrape opens hundreds. Generous enough never to
        // interrupt real work, tight enough that mass extraction is not silent.
        \Illuminate\Support\Facades\RateLimiter::for('clinical', fn ($request) => $request->user()
            ? \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()->getKey())
            : \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($request->ip()));

        // Admin-saved runtime settings (SMTP, push, reminder times) override the .env
        // config. Guarded internally so pre-migration artisan calls never crash.
        \App\Support\AppSettings::applyOverrides();

        // Bridge the data-driven capability resolver into Laravel's Gate so `$user->can('...')`,
        // `@can`, and Inertia `can` checks resolve against capabilities. Returning null (not
        // false) on a miss lets any non-capability Gate/Policy still run normally.
        Gate::before(function (?User $user, string $ability) {
            if ($user === null) {
                return null;
            }

            return AccessControl::allows($user, $ability) ? true : null;
        });
    }
}

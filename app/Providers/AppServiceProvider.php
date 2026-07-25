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
        // /up is what the container HEALTHCHECK and the proxy believe. Left as Laravel's
        // default it only proves PHP answered, so the container reported healthy while
        // every clinical page 500'd on a dead database — the failure most worth detecting
        // is the one it could not see. A connection check, deliberately NOT a query
        // against a table: this must still pass before the first migration runs, or the
        // container never becomes healthy on a fresh deployment.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Foundation\Events\DiagnosingHealth::class,
            fn () => \Illuminate\Support\Facades\DB::connection()->getPdo(),
        );

        // A brake on BULK extraction, not on work. Sized from the real ceiling: a resident
        // using save-on-blur across a 15-patient census touches four fields per patient,
        // so 60/min (the first attempt) throttled a fast clinician mid-handover — the
        // browser e2e suite caught it. 240/min is above anything a human generates and
        // still turns "scrape a year of sheets" into minutes of obvious, audited traffic.
        \Illuminate\Support\Facades\RateLimiter::for('clinical', fn ($request) => $request->user()
            ? \Illuminate\Cache\RateLimiting\Limit::perMinute(240)->by($request->user()->getKey())
            : \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->ip()));

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

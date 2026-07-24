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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

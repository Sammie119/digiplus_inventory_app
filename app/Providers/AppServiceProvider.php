<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Sign only the path + query of verification links, so the check
        // doesn't depend on the scheme/host PHP sees behind Nginx/TLS
        // (see the 'signed:relative' middleware in routes/auth.php).
        VerifyEmail::createUrlUsing(fn (User $user) => url(URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        )));

        // Super Admin is a platform-level flag, not a tenant-scoped Spatie
        // role — Spatie's "teams" pivot tables require a non-null team id,
        // which conflicts with Super Admin having tenant_id = null. This
        // bypass keeps that case out of the team-scoped RBAC entirely.
        Gate::before(fn (User $user) => $user->is_super_admin ? true : null);
    }
}

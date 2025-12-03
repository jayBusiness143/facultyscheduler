<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

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
        // Custom URL for password reset
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Define a rate limiter for API requests.
        // Use `API_RATE_LIMIT` in .env to customize the limit (requests per minute).
        // Set `API_RATE_LIMIT=0` to effectively disable throttling during local development.
        RateLimiter::for('api', function (Request $request) {
            $limit = (int) env('API_RATE_LIMIT', 60);

            if ($limit <= 0) {
                // Very large limit to effectively disable throttling without relying on Limit::none()
                return Limit::perMinute(PHP_INT_MAX)->by(optional($request->user())->id ?: $request->ip());
            }

            return Limit::perMinute($limit)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
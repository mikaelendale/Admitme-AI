<?php

use App\Http\Middleware\CheckFeatureQuota;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsureUserIsOnboarded;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ThrottleRequestsPerRoute;
use App\Jobs\BuildUserAiContextJob;
use App\Models\OnboardingProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // The browser extension's stateless API (bearer token, no session or CSRF).
            Route::group([], __DIR__.'/../routes/browser.php');
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(function (): void {
            $cutoff = now()->subDays(30);
            OnboardingProfile::query()
                ->where('status', 'completed')
                ->where(function ($q) use ($cutoff): void {
                    $q->whereNull('last_ai_context_at')
                        ->orWhere('last_ai_context_at', '<', $cutoff);
                })
                ->orderBy('id')
                ->chunkById(50, function ($profiles): void {
                    foreach ($profiles as $profile) {
                        BuildUserAiContextJob::dispatch($profile->user_id, false);
                    }
                });
        })->monthlyOn(1, '04:00')->name('refresh-stale-ai-context');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->preventRequestForgery(except: [
            'stripe/*',
        ]);
        $middleware->alias([
            'onboarded' => EnsureUserIsOnboarded::class,
            'platform.access' => EnsurePlatformAccess::class,
            'quota' => CheckFeatureQuota::class,
            // `throttle:N,M` counts each route on its own (Laravel's default shares one counter per user).
            'throttle' => ThrottleRequestsPerRoute::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

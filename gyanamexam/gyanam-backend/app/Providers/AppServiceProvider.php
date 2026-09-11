<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Shared-hosting guards for ~100 concurrent exams
        RateLimiter::for('exam-paper', function (Request $request) {
            return Limit::perMinute(6)->by('paper:' . ($request->user()?->id ?: $request->ip()));
        });
        RateLimiter::for('exam-heartbeat', function (Request $request) {
            return Limit::perMinute(3)->by('hb:' . ($request->user()?->id ?: $request->ip()));
        });
        RateLimiter::for('exam-answers', function (Request $request) {
            return Limit::perMinute(6)->by('ans:' . ($request->user()?->id ?: $request->ip()));
        });
        RateLimiter::for('exam-proctor', function (Request $request) {
            return Limit::perMinute(20)->by('proc:' . ($request->user()?->id ?: $request->ip()));
        });
        RateLimiter::for('exam-submit', function (Request $request) {
            return Limit::perMinute(4)->by('sub:' . ($request->user()?->id ?: $request->ip()));
        });
    }
}

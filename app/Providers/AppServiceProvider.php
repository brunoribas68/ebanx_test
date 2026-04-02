<?php

namespace App\Providers;

use App\Services\AccountService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * AccountService is bound as a singleton so the same instance is
         * reused if it is resolved multiple times within a single request
         * (e.g. in middleware + controller). This is a minor optimisation —
         * state persistence across requests is handled by file storage, not
         * by keeping this object alive in memory.
         */
        $this->app->singleton(AccountService::class);
    }

    public function boot(): void {}
}

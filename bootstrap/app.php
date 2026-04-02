<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
    /*
     * Mount API routes WITHOUT the default /api prefix.
     * The EBANX test suite expects routes at /reset, /balance, /event —
     * not /api/reset, /api/balance, /api/event.
     */
        using: function () {
            Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

<?php

use App\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

/*
 * All routes are mounted at the root (no /api prefix) because the
 * EBANX test suite calls /reset, /balance, and /event directly.
 * See bootstrap/app.php for how the prefix is removed.
 */

Route::post('/reset',   [AccountController::class, 'reset']);
Route::get('/balance',  [AccountController::class, 'balance']);
Route::post('/event',   [AccountController::class, 'event']);

<?php

use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\MeController;
use App\Http\Controllers\Platform\TwoFactorController;
use App\Platform\Auth\AccessTokens;
use Illuminate\Support\Facades\Route;

// Todo grupo de rota declara seu isolamento: `public`, `platform` ou `tenant` (ver FND-005).
Route::middleware('public')->get('/health', HealthController::class);

Route::middleware('tenant')->group(function () {
    Route::get('/tenant/current', CurrentTenantController::class);
});

// Equipe da plataforma (PLT-001): só nos hosts da plataforma.
Route::middleware('platform')->prefix('platform')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:platform-login');

    Route::middleware(['auth:sanctum', 'platform.active'])->group(function () {
        Route::post('auth/2fa/verify', [AuthController::class, 'verify'])
            ->middleware(['abilities:'.AccessTokens::CHALLENGE_VERIFY, 'throttle:platform-verify']);

        Route::middleware('abilities:'.AccessTokens::CHALLENGE_SETUP)->group(function () {
            Route::post('2fa/setup', [TwoFactorController::class, 'setup']);
            Route::post('2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:platform-verify');
        });

        Route::middleware('abilities:'.AccessTokens::ACCESS)->group(function () {
            Route::get('me', MeController::class);
            Route::post('auth/logout', [AuthController::class, 'logout']);
        });
    });
});

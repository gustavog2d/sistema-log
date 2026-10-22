<?php

use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\FeatureController;
use App\Http\Controllers\Platform\MeController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\PlanFeatureController;
use App\Http\Controllers\Platform\TwoFactorController;
use App\Http\Controllers\PublicPlanController;
use App\Platform\Auth\AccessTokens;
use Illuminate\Support\Facades\Route;

// Todo grupo de rota declara seu isolamento: `public`, `platform` ou `tenant` (ver FND-005).
Route::middleware('public')->group(function () {
    Route::get('/health', HealthController::class);
    Route::get('/plans', PublicPlanController::class);
});

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

            // Catálogo de planos (PLT-002): leitura para a equipe; escrita só para o super admin.
            Route::get('plans', [PlanController::class, 'index']);
            Route::get('plans/{plan}', [PlanController::class, 'show']);
            Route::get('plans/{plan}/entitlements', [PlanController::class, 'entitlements']);
            Route::get('features', [FeatureController::class, 'index']);

            Route::middleware('platform.role:super_admin')->group(function () {
                Route::post('plans', [PlanController::class, 'store']);
                Route::patch('plans/{plan}', [PlanController::class, 'update']);
                Route::put('plans/{plan}/features/{feature}', [PlanFeatureController::class, 'set']);
                Route::delete('plans/{plan}/features/{feature}', [PlanFeatureController::class, 'reset']);
                Route::post('features', [FeatureController::class, 'store']);
                Route::patch('features/{feature}', [FeatureController::class, 'update']);
            });
        });
    });
});

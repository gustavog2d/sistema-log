<?php

use App\Http\Controllers\CouponCheckController;
use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\CouponController;
use App\Http\Controllers\Platform\FeatureController;
use App\Http\Controllers\Platform\MeController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\PlanFeatureController;
use App\Http\Controllers\Platform\TenantAdminController;
use App\Http\Controllers\Platform\TwoFactorController;
use App\Http\Controllers\PublicPlanController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionManagementController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TenantAuth\InvitationController;
use App\Http\Controllers\TenantAuth\LoginController;
use App\Http\Controllers\TenantAuth\PasswordController;
use App\Http\Controllers\TenantAuth\SessionController;
use App\Http\Controllers\TenantAuth\TwoFactorController as TenantTwoFactorController;
use App\Platform\Auth\AccessTokens;
use Illuminate\Support\Facades\Route;

// Todo grupo de rota declara seu isolamento: `public`, `platform` ou `tenant` (ver FND-005).
Route::middleware('public')->group(function () {
    Route::get('/health', HealthController::class);
    Route::get('/plans', PublicPlanController::class)->middleware('throttle:public-catalog');
});

// Toda rota do assinante passa por `subscription.access`: com a assinatura suspensa ou cancelada, só leitura.
Route::middleware(['tenant', 'subscription.access'])->group(function () {
    Route::get('/tenant/current', CurrentTenantController::class);
    // Sempre permitido, mesmo com a assinatura suspensa: é como o assinante entende o bloqueio.
    Route::get('/subscription', SubscriptionController::class)->middleware(['auth.tenant', 'throttle:subscription-status']);
});

// Equipe (PLT-008): dona e gerente. A listagem passa mesmo suspenso; as mudanças respeitam `subscription.access`.
Route::middleware(['tenant', 'auth.tenant', 'subscription.access', 'role:owner,manager'])->prefix('users')->group(function () {
    Route::get('/', [TeamController::class, 'index']);
    // O acesso de suporte só lê a equipe: um convite ou uma mudança de papel sobreviveria ao token de 30 minutos.
    Route::post('invitations', [TeamController::class, 'invite'])->middleware(['no.impersonation', 'throttle:team-invite']);
    Route::delete('invitations/{id}', [TeamController::class, 'cancelInvitation'])->whereUuid('id')->middleware('no.impersonation');
    Route::patch('{id}', [TeamController::class, 'changeRole'])->whereUuid('id')->middleware('no.impersonation');
    Route::post('{id}/deactivate', [TeamController::class, 'deactivate'])->whereUuid('id')->middleware('no.impersonation');
    Route::post('{id}/reactivate', [TeamController::class, 'reactivate'])->whereUuid('id')->middleware('no.impersonation');
});

// Gestão da assinatura pela dona (PLT-007). Funciona também com a assinatura suspensa ou cancelada (`subscription.exempt`):
// é por aqui que se regulariza o pagamento.
Route::middleware(['tenant', 'subscription.exempt', 'auth.tenant', 'no.impersonation', 'owner', 'throttle:subscription-manage'])->prefix('subscription')->group(function () {
    Route::put('payment-method', [SubscriptionManagementController::class, 'paymentMethod'])->middleware('idempotent:payment-method');
    Route::post('cancel', [SubscriptionManagementController::class, 'cancel']);
    Route::post('reactivate', [SubscriptionManagementController::class, 'reactivate']);
    Route::get('invoices', [SubscriptionManagementController::class, 'invoices']);
});

// Autenticação do assinante (PLT-006). Funciona mesmo com a assinatura suspensa ou cancelada (`subscription.exempt`):
// quem não pagou precisa conseguir entrar para ler os próprios dados e entender o bloqueio.
Route::middleware(['tenant', 'subscription.exempt'])->prefix('auth')->group(function () {
    Route::post('login', [LoginController::class, 'login'])->middleware(['throttle:tenant-login', 'throttle:tenant-login-email']);
    Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:tenant-forgot');
    Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:tenant-reset');
    Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:invitation-accept');

    Route::post('2fa/verify', [LoginController::class, 'verify'])->middleware(['auth.tenant:challenge', 'throttle:tenant-verify']);

    Route::middleware('auth.tenant')->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::post('logout', [SessionController::class, 'logout']);
        Route::post('logout-all', [SessionController::class, 'logoutAll'])->middleware('no.impersonation');
        Route::post('password/change', [PasswordController::class, 'change'])->middleware(['no.impersonation', 'throttle:tenant-sensitive']);
        Route::post('2fa/disable', [TenantTwoFactorController::class, 'disable'])->middleware(['no.impersonation', 'throttle:tenant-sensitive']);
    });

    Route::middleware(['auth.tenant:access,setup', 'no.impersonation'])->group(function () {
        Route::post('2fa/setup', [TenantTwoFactorController::class, 'setup']);
        Route::post('2fa/confirm', [TenantTwoFactorController::class, 'confirm'])->middleware('throttle:tenant-verify');
    });
});

// Cadastro do assinante (PLT-003): público, só nos hosts da plataforma.
Route::middleware('platform')->group(function () {
    Route::get('signup/slug-availability', [SignupController::class, 'slugAvailability'])->middleware('throttle:slug-check');
    Route::post('signup/coupon-check', CouponCheckController::class)->middleware('throttle:coupon-check');
    Route::post('signup', [SignupController::class, 'store'])->middleware(['throttle:signup', 'idempotent:signup']);
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

            Route::get('coupons', [CouponController::class, 'index']);

            // Assinantes (PLT-009): a equipe lê e entra como assinante; as exceções e a suspensão são do super admin.
            Route::get('tenants', [TenantAdminController::class, 'index']);
            Route::get('tenants/{id}', [TenantAdminController::class, 'show'])->whereUuid('id');
            Route::post('tenants/{id}/impersonate', [TenantAdminController::class, 'impersonate'])->whereUuid('id')->middleware('throttle:platform-impersonate');

            Route::middleware('platform.role:super_admin')->group(function () {
                Route::post('tenants/{id}/suspend', [TenantAdminController::class, 'suspend'])->whereUuid('id');
                Route::post('tenants/{id}/unsuspend', [TenantAdminController::class, 'unsuspend'])->whereUuid('id');
                Route::post('tenants/{id}/extend-trial', [TenantAdminController::class, 'extendTrial'])->whereUuid('id');
                Route::put('tenants/{id}/entitlements/{feature}', [TenantAdminController::class, 'setEntitlement'])->whereUuid('id');
                Route::delete('tenants/{id}/entitlements/{feature}', [TenantAdminController::class, 'resetEntitlement'])->whereUuid('id');
                Route::post('coupons', [CouponController::class, 'store']);
                Route::patch('coupons/{id}', [CouponController::class, 'update'])->whereUuid('id');
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

<?php

use App\Http\Controllers\CurrentTenantController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Todo grupo de rota declara seu isolamento: `public`, `platform` ou `tenant` (ver FND-005).
Route::middleware('public')->get('/health', HealthController::class);

Route::middleware('tenant')->group(function () {
    Route::get('/tenant/current', CurrentTenantController::class);
});

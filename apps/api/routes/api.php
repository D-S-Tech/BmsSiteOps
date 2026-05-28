<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceMuteController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteDashboardController;
use App\Http\Controllers\Api\SourceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (public, versioned)
|--------------------------------------------------------------------------
|
| The /api/v1 surface is consumed by the SvelteKit frontend and any external
| API client. Authenticated with Sanctum bearer tokens and tenant-scoped via
| the 'tenant' middleware (sets CurrentTenant from the user's current_tenant_id).
|
*/

Route::get('v1/ping', fn () => response()->json([
    'pong' => true,
    'time' => now()->toIso8601String(),
]))->name('api.v1.ping');

Route::middleware(['auth:sanctum', 'tenant'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('me', fn (Request $request) => $request->user())->name('me');

    // Sites — read-only for now (created via admin / seeders)
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');

    // Site dashboard aggregations (Sprint 3)
    Route::get('sites/{site}/summary', [SiteDashboardController::class, 'summary'])->name('sites.summary');
    Route::get('sites/{site}/timeline', [SiteDashboardController::class, 'timeline'])->name('sites.timeline');

    // Sources — full CRUD
    Route::apiResource('sources', SourceController::class);

    // Devices — read + filters
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');

    // Operator workflow — device muting (Sprint 3.4)
    Route::post('devices/{device}/mute', [DeviceMuteController::class, 'mute'])->name('devices.mute');
    Route::post('devices/{device}/unmute', [DeviceMuteController::class, 'unmute'])->name('devices.unmute');

    // Events — read + filters
    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
});

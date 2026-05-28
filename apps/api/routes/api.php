<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceMuteController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ScriptController;
use App\Http\Controllers\Api\SiteBriefController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteDashboardController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\TriageDecisionController;
use App\Http\Controllers\Api\TriageRuleController;
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

    // AI Site Briefs (Sprint 4) — 'latest' before the index path.
    Route::get('sites/{site}/briefs/latest', [SiteBriefController::class, 'latest'])->name('sites.briefs.latest');
    Route::get('sites/{site}/briefs', [SiteBriefController::class, 'index'])->name('sites.briefs.index');

    // Triage (Sprint 5) — rules + per-site decision audit log
    Route::get('triage-rules', [TriageRuleController::class, 'index'])->name('triage-rules.index');
    Route::get('triage-rules/{rule}', [TriageRuleController::class, 'show'])->name('triage-rules.show');
    Route::get('sites/{site}/triage-decisions', [TriageDecisionController::class, 'index'])->name('sites.triage-decisions.index');

    // Scripts (Sprint 6) — AI script authoring (Qwen 2.5 Coder via Ollama)
    Route::get('scripts', [ScriptController::class, 'index'])->name('scripts.index');
    Route::post('scripts', [ScriptController::class, 'store'])->name('scripts.store');
    Route::get('scripts/{script}', [ScriptController::class, 'show'])->name('scripts.show');

    // Documents (Sprint 7) — knowledge base for RAG / Site Q&A
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

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

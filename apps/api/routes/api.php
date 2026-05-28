<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (public, versioned)
|--------------------------------------------------------------------------
|
| The /api/v1 surface is consumed by the SvelteKit frontend and any external
| API client, authenticated with Sanctum bearer tokens and tenant-scoped via
| the authenticated user's current_tenant_id. CRUD resources land in Sprint 1.3.
|
*/

Route::get('v1/ping', fn () => response()->json([
    'pong' => true,
    'time' => now()->toIso8601String(),
]))->name('api.v1.ping');

Route::middleware('auth:sanctum')->get('v1/me', fn (Request $request) => $request->user())
    ->name('api.v1.me');

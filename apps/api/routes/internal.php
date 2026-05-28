<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\BriefContextController;
use App\Http\Controllers\Internal\SourceSyncController;
use App\Http\Controllers\Internal\StoreSiteBriefController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal routes (worker → API)
|--------------------------------------------------------------------------
|
| These endpoints are called by the Python worker, authenticated by the
| HMAC VerifyWorkerSignature middleware (alias: worker.signature). They are
| NOT part of the public /api/v1 surface and carry no user session. Mounted
| under the /internal prefix in bootstrap/app.php.
|
*/

Route::middleware('worker.signature')->prefix('internal')->group(function () {
    // One source's poll result: status + discovered devices + new events.
    Route::post('sources/{source}/sync', SourceSyncController::class)
        ->whereNumber('source')
        ->name('internal.sources.sync');

    // AI Site Brief (Sprint 4): worker fetches context, then pushes the brief.
    Route::get('sites/{site}/brief-context', BriefContextController::class)
        ->whereNumber('site')
        ->name('internal.sites.brief-context');
    Route::post('sites/{site}/briefs', StoreSiteBriefController::class)
        ->whereNumber('site')
        ->name('internal.sites.briefs.store');
});

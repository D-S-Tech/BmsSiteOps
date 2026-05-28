<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\SourceSyncController;
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
});

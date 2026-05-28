<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\BriefContextController;
use App\Http\Controllers\Internal\ClaimDocumentController;
use App\Http\Controllers\Internal\ClaimScriptController;
use App\Http\Controllers\Internal\SourceSyncController;
use App\Http\Controllers\Internal\StoreSiteBriefController;
use App\Http\Controllers\Internal\SubmitDocumentEmbeddingsController;
use App\Http\Controllers\Internal\SubmitScriptResultController;
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

    // AI Scripts (Sprint 6): worker claims the next pending generation, then
    // pushes the result back when the model finishes.
    Route::post('scripts/claim', ClaimScriptController::class)
        ->name('internal.scripts.claim');
    Route::post('scripts/{script}/result', SubmitScriptResultController::class)
        ->whereNumber('script')
        ->name('internal.scripts.result');

    // RAG documents (Sprint 7.2): worker claims a pending document, embeds
    // its chunks via the LLM seam, pushes the embeddings back.
    Route::post('documents/claim', ClaimDocumentController::class)
        ->name('internal.documents.claim');
    Route::post('documents/{document}/embeddings', SubmitDocumentEmbeddingsController::class)
        ->whereNumber('document')
        ->name('internal.documents.embeddings');
});

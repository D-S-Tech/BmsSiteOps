<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Support\CurrentTenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Internal endpoint: a worker claims the next pending document for embedding.
 *
 * Same pattern as ClaimScriptController (Sprint 6.1) — atomic claim under
 * lockForUpdate(), cross-tenant query (the worker doesn't pre-pick a tenant;
 * the document row carries it), 204 on an empty queue.
 *
 * The response embeds the chunks list — the worker needs the chunk text to
 * compute embeddings.
 */
class ClaimDocumentController extends Controller
{
    public function __invoke(): DocumentResource|Response
    {
        $claimed = DB::transaction(function (): ?Document {
            $document = Document::withoutGlobalScopes()
                ->where('status', DocumentStatus::Pending->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                return null;
            }

            CurrentTenant::set($document->tenant_id);

            $document->forceFill([
                'status' => DocumentStatus::Embedding,
            ])->save();

            $document->load('chunks');

            return $document;
        });

        if ($claimed === null) {
            return response()->noContent();
        }

        return DocumentResource::make($claimed);
    }
}

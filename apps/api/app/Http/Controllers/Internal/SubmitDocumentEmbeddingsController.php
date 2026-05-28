<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SubmitDocumentEmbeddingsRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Internal endpoint: worker submits the embedding result for a document.
 *
 * Only documents currently in Embedding state can be settled — a worker
 * submitting against any other state means stale work, and we return 409
 * Conflict so the worker logs and moves on without losing real results.
 * Same defensive pattern as SubmitScriptResultController (Sprint 6.1).
 *
 * On status='ready' the chunks array is applied row by row (chunk_id must
 * belong to the document, else 422). On status='failed' the error is
 * persisted and the document is left without embeddings.
 */
class SubmitDocumentEmbeddingsController extends Controller
{
    public function __invoke(SubmitDocumentEmbeddingsRequest $request, int $documentId): DocumentResource
    {
        $document = Document::withoutGlobalScopes()->findOrFail($documentId);

        if ($document->status !== DocumentStatus::Embedding) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Document is not in the embedding state.',
                    'current_status' => $document->status->value,
                ], 409)
            );
        }

        CurrentTenant::set($document->tenant_id);

        $data = $request->validated();
        $status = DocumentStatus::from($data['status']);

        DB::transaction(function () use ($document, $status, $data): void {
            if ($status === DocumentStatus::Ready) {
                $this->applyChunkEmbeddings($document, $data['chunks'] ?? []);

                $document->forceFill([
                    'status' => DocumentStatus::Ready,
                    'error' => null,
                    'embedded_at' => now(),
                ])->save();

                return;
            }

            // failed
            $document->forceFill([
                'status' => DocumentStatus::Failed,
                'error' => $data['error'],
            ])->save();
        });

        $document->load('chunks');

        return DocumentResource::make($document);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     */
    private function applyChunkEmbeddings(Document $document, array $chunks): void
    {
        $ownChunkIds = $document->chunks()->pluck('id')->all();

        foreach ($chunks as $entry) {
            $chunkId = (int) $entry['id'];
            if (! in_array($chunkId, $ownChunkIds, true)) {
                throw new HttpResponseException(
                    response()->json([
                        'message' => "Chunk id {$chunkId} does not belong to document {$document->id}.",
                    ], 422)
                );
            }

            DocumentChunk::query()->where('id', $chunkId)->update([
                'embedding' => json_encode($entry['embedding']),
                'embedding_model' => $entry['embedding_model'],
                'token_count' => $entry['token_count'] ?? null,
                'embedded_at' => now(),
            ]);
        }
    }
}

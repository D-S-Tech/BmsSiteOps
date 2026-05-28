<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Documents\DocumentChunker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge-base documents for RAG.
 *
 * Operators POST text content (manually, or from an upload pipeline that
 * pre-extracts text). The controller chunks the content synchronously
 * (fast pure-PHP operation) and persists Document + DocumentChunk rows in
 * one transaction. The chunks are returned with embeddings still null;
 * the worker (Sprint 7.2) claims pending documents and fills the embeddings
 * in. Sprint 7.3 then uses those embeddings for Q&A retrieval.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentChunker $chunker) {}

    /**
     * GET /api/v1/documents — newest first, paginated. chunks_count attached
     * via withCount so the list view shows progress without loading every
     * chunk's content.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) min(100, max(1, $request->integer('per_page', 25)));

        $documents = Document::query()
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return DocumentResource::collection($documents);
    }

    /**
     * GET /api/v1/documents/{document}
     *
     * Eager-loads chunks so a single round trip is enough to render the
     * full document detail page on the web side. Pass ?include_content=1
     * to also include the source text.
     */
    public function show(Document $document): DocumentResource
    {
        $document->load('chunks');

        return DocumentResource::make($document);
    }

    /**
     * POST /api/v1/documents — store + chunk a new document.
     *
     * The whole operation runs in one transaction so a partial chunking
     * never leaves the registry inconsistent.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $chunks = $this->chunker->chunk($data['content']);

        $document = DB::transaction(function () use ($data, $chunks, $request): Document {
            /** @var Document $document */
            $document = Document::create([
                'site_id' => $data['site_id'] ?? null,
                'uploaded_by_user_id' => $request->user()?->id,
                'title' => $data['title'],
                'source_type' => $data['source_type'] ?? 'manual',
                'source_ref' => $data['source_ref'] ?? null,
                'content' => $data['content'],
                'metadata' => $data['metadata'] ?? null,
                'status' => DocumentStatus::Pending,
            ]);

            foreach ($chunks as $position => $content) {
                $document->chunks()->create([
                    'position' => $position,
                    'content' => $content,
                ]);
            }

            return $document;
        });

        $document->loadCount('chunks');
        $document->load('chunks');

        return DocumentResource::make($document)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/v1/documents/{document}
     *
     * Hard delete; chunks cascade via the FK constraint.
     */
    public function destroy(Document $document): Response
    {
        $document->delete();

        return response()->noContent();
    }
}

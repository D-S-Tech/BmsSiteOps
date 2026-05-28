<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\QuestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Services\RAG\QaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Site Q&A — operators ask questions, the controller orchestrates the RAG
 * pipeline (embed -> search -> generate) via QaService, persists the result,
 * and returns the resource. Synchronous from the operator's perspective.
 */
class QaController extends Controller
{
    public function __construct(private readonly QaService $service) {}

    /**
     * GET /api/v1/qa — newest first, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) min(100, max(1, $request->integer('per_page', 25)));

        $questions = Question::query()
            ->orderByDesc('asked_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return QuestionResource::collection($questions);
    }

    /**
     * GET /api/v1/qa/{question}
     */
    public function show(Question $question): QuestionResource
    {
        return QuestionResource::make($question);
    }

    /**
     * POST /api/v1/qa — create the Question row, then run the pipeline
     * synchronously. The response carries the final (Ready or Failed) state.
     */
    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $question = Question::create([
            'site_id' => $data['site_id'] ?? null,
            'asked_by_user_id' => $request->user()?->id,
            'question' => $data['question'],
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        // Synchronous orchestration. Latency ~1-15s depending on the LLM
        // backend; for an interactive UX this is acceptable. If the worker
        // is unreachable the pipeline swallows the exception and flips the
        // row to Failed — the response still carries the row.
        $this->service->process($question);

        return QuestionResource::make($question->refresh())
            ->response()
            ->setStatusCode(201);
    }
}

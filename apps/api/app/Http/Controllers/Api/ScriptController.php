<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ScriptLanguage;
use App\Enums\ScriptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScriptRequest;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public API for AI-generated script requests.
 *
 * The flow is async: POST creates a Requested row and returns it immediately;
 * the worker picks it up and pushes the result back via the internal HMAC
 * channel; the client polls GET /{id} (or just refreshes) until status leaves
 * the pending set.
 */
class ScriptController extends Controller
{
    /**
     * GET /api/v1/scripts — newest first, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) min(100, max(1, $request->integer('per_page', 25)));

        $scripts = Script::query()
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ScriptResource::collection($scripts);
    }

    /**
     * GET /api/v1/scripts/{script}
     */
    public function show(Script $script): ScriptResource
    {
        return ScriptResource::make($script);
    }

    /**
     * POST /api/v1/scripts — request a new generation.
     */
    public function store(StoreScriptRequest $request): JsonResponse
    {
        $data = $request->validated();

        $script = Script::create([
            'requested_by_user_id' => $request->user()?->id,
            'title' => $data['title'],
            'prompt' => $data['prompt'],
            'language' => ScriptLanguage::from($data['language']),
            'status' => ScriptStatus::Requested,
            'requested_at' => now(),
        ]);

        return ScriptResource::make($script)
            ->response()
            ->setStatusCode(201);
    }
}

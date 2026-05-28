<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\ScriptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SubmitScriptResultRequest;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * Internal endpoint: worker submits the result of a script generation.
 *
 * Accepts either status=ready (with content + model) or status=failed (with
 * error). Only scripts currently in the Generating state can be settled —
 * a worker submitting against any other state means stale work, and we
 * return 409 Conflict so the worker logs and moves on without losing real
 * results.
 */
class SubmitScriptResultController extends Controller
{
    public function __invoke(SubmitScriptResultRequest $request, int $scriptId): ScriptResource
    {
        $script = Script::withoutGlobalScopes()->findOrFail($scriptId);

        if ($script->status !== ScriptStatus::Generating) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Script is not in the generating state.',
                    'current_status' => $script->status->value,
                ], 409)
            );
        }

        CurrentTenant::set($script->tenant_id);

        $data = $request->validated();
        $status = ScriptStatus::from($data['status']);

        $script->forceFill([
            'status' => $status,
            'content' => $status === ScriptStatus::Ready ? $data['content'] : null,
            'model' => $data['model'] ?? null,
            'error' => $status === ScriptStatus::Failed ? $data['error'] : null,
            'metadata' => $data['metadata'] ?? null,
            'generated_at' => isset($data['generated_at'])
                ? Carbon::parse($data['generated_at'])
                : now(),
        ])->save();

        return ScriptResource::make($script);
    }
}

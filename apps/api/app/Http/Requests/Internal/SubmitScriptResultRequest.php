<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\ScriptStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the worker -> internal API result submission.
 *
 * The worker submits either a success payload (status=ready + content + model)
 * or a failure payload (status=failed + error).
 */
class SubmitScriptResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([ScriptStatus::Ready->value, ScriptStatus::Failed->value]),
            ],
            'content' => ['nullable', 'string', 'max:200000'],
            'model' => ['nullable', 'string', 'max:100'],
            'error' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'generated_at' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, array<int, callable>>
     */
    public function after(): array
    {
        return [
            function (Validator $v): void {
                $status = $this->input('status');
                if ($status === ScriptStatus::Ready->value && ! filled($this->input('content'))) {
                    $v->errors()->add('content', 'Content is required when status is ready.');
                }
                if ($status === ScriptStatus::Failed->value && ! filled($this->input('error'))) {
                    $v->errors()->add('error', 'Error is required when status is failed.');
                }
            },
        ];
    }
}

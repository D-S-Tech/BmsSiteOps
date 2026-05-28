<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the worker -> internal API embedding result submission.
 *
 *  ready  -> { status: 'ready', chunks: [{id, embedding, embedding_model, token_count?}, ...] }
 *  failed -> { status: 'failed', error: '<message>' }
 */
class SubmitDocumentEmbeddingsRequest extends FormRequest
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
                Rule::in([DocumentStatus::Ready->value, DocumentStatus::Failed->value]),
            ],
            'error' => ['nullable', 'string', 'max:5000'],

            'chunks' => ['nullable', 'array'],
            'chunks.*.id' => ['required_with:chunks', 'integer'],
            'chunks.*.embedding' => ['required_with:chunks', 'array', 'min:1'],
            'chunks.*.embedding.*' => ['numeric'],
            'chunks.*.embedding_model' => ['required_with:chunks', 'string', 'max:100'],
            'chunks.*.token_count' => ['nullable', 'integer', 'min:0'],
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
                if ($status === DocumentStatus::Ready->value && ! is_array($this->input('chunks'))) {
                    $v->errors()->add('chunks', 'chunks are required when status is ready.');
                }
                if ($status === DocumentStatus::Failed->value && ! filled($this->input('error'))) {
                    $v->errors()->add('error', 'error is required when status is failed.');
                }
            },
        ];
    }
}

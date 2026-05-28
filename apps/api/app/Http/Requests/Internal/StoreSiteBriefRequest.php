<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a generated brief pushed by the worker. Authorization is handled
 * by the VerifyWorkerSignature (HMAC) middleware, not here.
 */
class StoreSiteBriefRequest extends FormRequest
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
            'summary' => ['required', 'string', 'max:20000'],
            'model' => ['required', 'string', 'max:128'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'generated_at' => ['required', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            // Optional — null means tenant-wide (not bound to a single site).
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'source_type' => ['nullable', 'string', 'max:32'],
            'source_ref' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:2000000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\NiagaraTransport;
use App\Enums\SourceKind;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The site must belong to the current tenant — enforce via exists
            // scoped to tenant_id so a user can't attach a source to another
            // tenant's site.
            'site_id' => [
                'required',
                'integer',
                Rule::exists('sites', 'id')->where('tenant_id', CurrentTenant::id()),
            ],
            'kind' => ['required', Rule::enum(SourceKind::class)],
            // Transport is required for Niagara sources, forbidden otherwise.
            'transport' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('kind') === SourceKind::Niagara->value),
                Rule::enum(NiagaraTransport::class),
            ],
            'name' => ['required', 'string', 'max:200'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'credentials' => ['nullable', 'array'],
            'poll_interval_seconds' => ['nullable', 'integer', 'min:10', 'max:86400'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

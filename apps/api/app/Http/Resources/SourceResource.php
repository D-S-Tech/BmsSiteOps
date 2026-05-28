<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Source
 *
 * SECURITY: this resource deliberately NEVER exposes the `credentials`
 * attribute. Credentials are read only by the worker over the internal
 * HMAC channel — never returned by the public API.
 */
class SourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'transport' => $this->transport?->value,
            'name' => $this->name,
            'base_url' => $this->base_url,
            // credentials intentionally omitted — see class docblock.
            'has_credentials' => ! empty($this->credentials),
            'poll_interval_seconds' => $this->poll_interval_seconds,
            'is_active' => $this->is_active,
            'last_status' => $this->last_status->value,
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'metadata' => $this->metadata,
            'site' => SiteResource::make($this->whenLoaded('site')),
            'devices_count' => $this->whenCounted('devices'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

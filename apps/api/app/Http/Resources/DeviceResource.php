<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->source_id,
            'site_id' => $this->site_id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'source' => SourceResource::make($this->whenLoaded('source')),
            'site' => SiteResource::make($this->whenLoaded('site')),
            'events_count' => $this->whenCounted('events'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

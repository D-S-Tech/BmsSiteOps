<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'source_id' => $this->source_id,
            'site_id' => $this->site_id,
            'kind' => $this->kind->value,
            'metric' => $this->metric,
            'value' => $this->value,
            'severity' => $this->severity?->value,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'device' => DeviceResource::make($this->whenLoaded('device')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\DeviceStatus;
use App\Enums\EventSeverity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a worker sync payload posted to the internal ingestion endpoint.
 *
 * Authorization is handled by the VerifyWorkerSignature middleware, not here.
 */
class SourceSyncRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'in:ok,error'],
            'error' => ['nullable', 'string', 'max:5000'],

            'devices' => ['sometimes', 'array'],
            'devices.*.external_id' => ['required', 'string', 'max:255'],
            'devices.*.name' => ['required', 'string', 'max:255'],
            'devices.*.type' => ['nullable', 'string', 'max:64'],
            'devices.*.status' => ['nullable', 'string', 'in:'.$this->enumValues(DeviceStatus::cases())],
            'devices.*.last_seen_at' => ['nullable', 'date'],
            'devices.*.metadata' => ['nullable', 'array'],

            'events' => ['sometimes', 'array'],
            'events.*.device_external_id' => ['required', 'string', 'max:255'],
            'events.*.metric' => ['required', 'string', 'max:128'],
            'events.*.value' => ['nullable'],
            'events.*.severity' => ['nullable', 'string', 'in:'.$this->enumValues(EventSeverity::cases())],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     */
    private function enumValues(array $cases): string
    {
        return implode(',', array_map(static fn ($c) => $c->value, $cases));
    }
}

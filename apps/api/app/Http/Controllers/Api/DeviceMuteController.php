<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MuteDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Support\Carbon;

/**
 * Operator workflow: mute / unmute a device.
 *
 * Muting suppresses a noisy device from actionable views (the site dashboard's
 * recent-events list and the muted-device count) without touching the
 * append-only events table. A mute is indefinite unless `until` is given.
 */
class DeviceMuteController extends Controller
{
    public function mute(MuteDeviceRequest $request, Device $device): DeviceResource
    {
        $until = $request->filled('until')
            ? Carbon::parse($request->validated('until'))
            : null;

        $device->update([
            'is_muted' => true,
            'muted_until' => $until,
        ]);

        return DeviceResource::make($device);
    }

    public function unmute(Device $device): DeviceResource
    {
        $device->update([
            'is_muted' => false,
            'muted_until' => null,
        ]);

        return DeviceResource::make($device);
    }
}

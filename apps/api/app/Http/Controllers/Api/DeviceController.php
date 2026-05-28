<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = Device::query()
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->integer('site_id')))
            ->when($request->filled('source_id'), fn ($q) => $q->where('source_id', $request->integer('source_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return DeviceResource::collection($devices);
    }

    public function show(Device $device): DeviceResource
    {
        $device->load(['source', 'site'])->loadCount('events');

        return DeviceResource::make($device);
    }
}

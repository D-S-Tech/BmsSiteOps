<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = Event::query()
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->integer('site_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('source_id'), fn ($q) => $q->where('source_id', $request->integer('source_id')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('metric'), fn ($q) => $q->where('metric', $request->string('metric')))
            ->when($request->filled('since'), fn ($q) => $q->where('occurred_at', '>=', $request->date('since')))
            ->when($request->filled('until'), fn ($q) => $q->where('occurred_at', '<=', $request->date('until')))
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return EventResource::collection($events);
    }

    public function show(Event $event): EventResource
    {
        $event->load('device');

        return EventResource::make($event);
    }
}

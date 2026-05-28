<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSourceRequest;
use App\Http\Requests\Api\UpdateSourceRequest;
use App\Http\Resources\SourceResource;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SourceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sources = Source::query()
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->integer('site_id')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount('devices')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return SourceResource::collection($sources);
    }

    public function store(StoreSourceRequest $request): JsonResponse
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        $source = Source::create($request->validated());

        return SourceResource::make($source)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Source $source): SourceResource
    {
        $source->loadCount('devices')->load('site');

        return SourceResource::make($source);
    }

    public function update(UpdateSourceRequest $request, Source $source): SourceResource
    {
        $source->update($request->validated());

        return SourceResource::make($source);
    }

    public function destroy(Source $source): JsonResponse
    {
        $source->delete();

        return response()->json(status: 204);
    }
}

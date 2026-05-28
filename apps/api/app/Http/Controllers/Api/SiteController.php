<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SiteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sites = Site::query()
            ->withCount(['sources', 'devices'])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return SiteResource::collection($sites);
    }

    public function show(Site $site): SiteResource
    {
        $site->loadCount(['sources', 'devices']);

        return SiteResource::make($site);
    }
}

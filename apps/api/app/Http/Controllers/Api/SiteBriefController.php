<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteBriefResource;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read access to a site's AI Site Briefs. Tenant-scoped via the route's
 * 'tenant' middleware and the models' global scope.
 */
class SiteBriefController extends Controller
{
    /**
     * GET /api/v1/sites/{site}/briefs — newest first, paginated.
     */
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        $perPage = (int) min(100, max(1, $request->integer('per_page', 15)));

        $briefs = $site->briefs()
            ->orderByDesc('generated_at')
            ->paginate($perPage);

        return SiteBriefResource::collection($briefs);
    }

    /**
     * GET /api/v1/sites/{site}/briefs/latest — the most recent brief.
     */
    public function latest(Site $site): SiteBriefResource
    {
        $brief = $site->briefs()
            ->orderByDesc('generated_at')
            ->first();

        if ($brief === null) {
            throw new NotFoundHttpException('No brief has been generated for this site yet.');
        }

        return SiteBriefResource::make($brief);
    }
}

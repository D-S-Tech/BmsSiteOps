<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\SiteContextService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal endpoint: site context for AI Site Brief generation.
 *
 * Called by the worker over the HMAC channel (no user session). The site is
 * resolved WITHOUT the tenant global scope; the tenant is then set from the
 * site itself before any tenant-scoped aggregation runs — mirroring the
 * ingestion path.
 */
class BriefContextController extends Controller
{
    public function __construct(private readonly SiteContextService $context) {}

    public function __invoke(Request $request, int $siteId): JsonResponse
    {
        $site = Site::withoutGlobalScopes()->findOrFail($siteId);
        CurrentTenant::set($site->tenant_id);

        $hours = max(1, min(168, $request->integer('hours', 24)));

        return response()->json($this->context->briefContext($site, $hours));
    }
}

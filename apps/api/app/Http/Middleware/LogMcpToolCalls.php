<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\McpAuditEntry;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one mcp_audit_entries row for every inbound request that carried
 * an X-MCP-Tool header (Sprint 9.2).
 *
 * The worker's LaravelClient attaches that header on every call originating
 * from an MCP tool invocation (see apps/worker/app/mcp/laravel_client.py).
 * A request without the header is just a regular API call and goes
 * untouched — no audit row written, no performance impact.
 *
 * Failure mode: if writing the audit row throws, we log and swallow rather
 * than corrupt the actual API response. Auditing is best-effort observability,
 * not a hard dependency of the request lifecycle.
 *
 * Truncation: request_payload is capped at 8 KB (~2000 chars after JSON
 * encoding for typical objects) to keep the table small. Long bodies still
 * audit, just truncated with a marker.
 */
class LogMcpToolCalls
{
    private const PAYLOAD_MAX_BYTES = 8192;

    public function handle(Request $request, Closure $next): Response
    {
        $toolName = $request->header('X-MCP-Tool');

        // Not an MCP-originated call — skip auditing entirely.
        if (! is_string($toolName) || $toolName === '') {
            return $next($request);
        }

        $startedAt = microtime(true);

        $response = $next($request);

        try {
            $this->writeAuditEntry($request, $response, $toolName, $startedAt);
        } catch (Throwable $e) {
            // Auditing failed but the response is fine — log and move on.
            // This is the only safe failure mode for observability code.
            Log::warning('mcp_audit: failed to write entry', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    private function writeAuditEntry(
        Request $request,
        Response $response,
        string $toolName,
        float $startedAt,
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        McpAuditEntry::create([
            'tenant_id' => CurrentTenant::id(),
            'user_id' => $request->user()?->id,
            'tool_name' => substr($toolName, 0, 100),
            'method' => $request->method(),
            'path' => substr($request->path(), 0, 255),
            'request_payload' => $this->capturePayload($request),
            'response_status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Capture the inbound JSON body, truncated to PAYLOAD_MAX_BYTES.
     * GET requests have no body; we return null in that case rather than
     * an empty array, so the audit row says 'this was a GET' unambiguously.
     */
    private function capturePayload(Request $request): ?array
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $data = $request->all();
        if ($data === []) {
            return null;
        }

        $encoded = json_encode($data);
        if ($encoded !== false && strlen($encoded) > self::PAYLOAD_MAX_BYTES) {
            return ['_truncated' => true, '_size_bytes' => strlen($encoded)];
        }

        return $data;
    }
}

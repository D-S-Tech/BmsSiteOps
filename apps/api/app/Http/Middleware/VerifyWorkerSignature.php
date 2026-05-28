<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the Python worker to the internal ingestion API.
 *
 * The worker signs each request with the shared WORKER_INTERNAL_KEY secret:
 *
 *     payload   = "{timestamp}.{raw_request_body}"
 *     signature = hex( hmac_sha256(WORKER_INTERNAL_KEY, payload) )
 *
 * and sends:
 *
 *     X-Worker-Timestamp: <unix seconds>
 *     X-Worker-Signature: <hex signature>
 *
 * The middleware recomputes the signature and compares it in constant time.
 * Requests whose timestamp drifts beyond services.worker.max_clock_skew are
 * rejected to limit replay attacks. There is no user/session — tenant context
 * is established downstream from the source being synced.
 */
class VerifyWorkerSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = config('services.worker.internal_key');

        if (empty($key) || $key === 'changeme' || $key === 'CHANGEME') {
            abort(503, 'Worker internal key is not configured.');
        }

        $timestamp = $request->header('X-Worker-Timestamp');
        $signature = $request->header('X-Worker-Signature');

        if (! is_string($timestamp) || ! is_string($signature) || $timestamp === '' || $signature === '') {
            abort(401, 'Missing worker authentication headers.');
        }

        $skew = (int) config('services.worker.max_clock_skew', 300);
        if (abs(time() - (int) $timestamp) > $skew) {
            abort(401, 'Worker request timestamp is out of range.');
        }

        $payload = $timestamp.'.'.$request->getContent();
        $expected = hash_hmac('sha256', $payload, (string) $key);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid worker signature.');
        }

        return $next($request);
    }
}

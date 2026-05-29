<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP tool-call audit log (Sprint 9.2).
 *
 * One row per inbound API request that carried an `X-MCP-Tool` header,
 * written by the LogMcpToolCalls middleware. Captures who (Sanctum user
 * + tenant), what (tool name, method, path), with what input (payload),
 * and how it went (response status, duration).
 *
 * Designed to be append-only from the application; cleaned periodically
 * by `php artisan audit:prune-mcp --days=N`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_entries', function (Blueprint $table) {
            $table->id();

            // Tenant context. Nullable for the rare case a request reached
            // the middleware without a resolved CurrentTenant (e.g. token
            // not yet scoped to a tenant); operators see those as orphans
            // and can investigate.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // The Sanctum-authenticated user, if any. Nullable in the same
            // defensive spirit — the middleware writes the row even if the
            // call slipped past auth, since that itself is audit-worthy.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Which MCP tool the worker said it was calling for. Comes
            // straight from the X-MCP-Tool request header.
            $table->string('tool_name', 100);

            // HTTP method + path, so the audit trail is self-contained
            // (no need to cross-reference the worker's MCP server source).
            $table->string('method', 10);
            $table->string('path', 255);

            // Inbound JSON body, truncated to 8 KB to keep the table small.
            // For POST /qa, the question text + site_id. For GET, often null.
            $table->json('request_payload')->nullable();

            // The HTTP status code we sent back.
            $table->unsignedSmallInteger('response_status');

            // Wall time in ms. Useful for spotting slow tools (Q&A can
            // take 5-10s if the LLM is cold; list_sites should be <50ms).
            $table->unsignedInteger('duration_ms');

            // Client IP (45 chars = IPv6 max). Operators may want to
            // cross-reference against the MCP_IP_ALLOWLIST.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // Indexes shaped around the typical query: 'show me the last N
            // calls for tenant X' / 'all calls to tool Y in the last 24h'.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tool_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_entries');
    }
};

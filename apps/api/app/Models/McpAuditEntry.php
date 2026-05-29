<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One audit-log row per inbound API request that carried an X-MCP-Tool
 * header (Sprint 9.2).
 *
 * Written by the LogMcpToolCalls middleware; read by the Filament
 * resource for ops review and by the audit:prune-mcp command for
 * retention.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string $tool_name
 * @property string $method
 * @property string $path
 * @property array|null $request_payload
 * @property int $response_status
 * @property int $duration_ms
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'user_id',
    'tool_name',
    'method',
    'path',
    'request_payload',
    'response_status',
    'duration_ms',
    'ip_address',
])]
class McpAuditEntry extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

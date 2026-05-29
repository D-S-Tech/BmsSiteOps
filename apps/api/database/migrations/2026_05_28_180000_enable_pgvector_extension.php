<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable the pgvector extension on PostgreSQL.
 *
 * No-op on SQLite (CI) and MySQL. The vector type + similarity operators
 * (<->, <#>, <=>) become available globally in the database after this.
 *
 * Requires Postgres 11+ with the pgvector package installed on the host
 * (postgresql-16-pgvector on Debian/Ubuntu; included by default in
 * pgvector/pgvector:pg16 image). The production docker-compose.prod.yml
 * uses postgres:16-alpine — operators must either swap the image to
 * pgvector/pgvector:pg16 or apt-install postgresql-16-pgvector inside
 * the container before running this migration.
 *
 * Same posture as TimescaleDB hypertable conversion (ADR 0008): the
 * application-level code path stays the same on SQLite/CI; the production
 * PG optimization activates only when the operator has installed the
 * extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // Don't auto-drop the extension; other databases on the same
        // cluster (and other migrations after this one) may depend on it.
        // Operator can `DROP EXTENSION vector` manually if needed.
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

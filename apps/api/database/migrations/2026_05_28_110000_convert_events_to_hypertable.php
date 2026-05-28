<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert the events table to a TimescaleDB hypertable, with retention and
 * compression policies.
 *
 * PostgreSQL/TimescaleDB only. On any other connection (e.g. the SQLite test
 * database) this migration is a deliberate no-op — events stays an ordinary
 * table and every query in the app continues to work unchanged.
 *
 * HARDWARE/DB VALIDATION: the TimescaleDB path below is written per the
 * TimescaleDB docs but is NOT exercised by CI (CI runs on SQLite). Validate
 * against a real TimescaleDB instance before relying on it in production.
 *
 * create_hypertable(migrate_data => true) cannot run inside a transaction, so
 * this migration opts out of Laravel's automatic migration transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // Non-PostgreSQL (tests/dev on SQLite): events stays a plain table.
            return;
        }

        $retention = (int) config('bmssiteops.events.retention_days', 90);
        $compressAfter = (int) config('bmssiteops.events.compression_after_days', 7);
        $chunkDays = (int) config('bmssiteops.events.chunk_interval_days', 7);

        // TimescaleDB must be installed on the server; CREATE EXTENSION is
        // idempotent.
        DB::statement('CREATE EXTENSION IF NOT EXISTS timescaledb');

        // A hypertable requires the partitioning column (occurred_at) to be
        // part of every unique index, so fold occurred_at into the primary key.
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_pkey');
        DB::statement('ALTER TABLE events ADD PRIMARY KEY (id, occurred_at)');

        // Convert to a hypertable, migrating any existing rows.
        DB::statement(
            "SELECT create_hypertable('events', 'occurred_at', "
            ."chunk_time_interval => INTERVAL '{$chunkDays} days', "
            .'migrate_data => true, if_not_exists => true)'
        );

        // Retention: drop chunks older than the configured window.
        DB::statement(
            "SELECT add_retention_policy('events', INTERVAL '{$retention} days', if_not_exists => true)"
        );

        // Compression: compress chunks older than the configured window,
        // segmented by site for efficient per-site reads.
        DB::statement(
            'ALTER TABLE events SET ('
            .'timescaledb.compress, '
            ."timescaledb.compress_segmentby = 'site_id', "
            ."timescaledb.compress_orderby = 'occurred_at DESC')"
        );
        DB::statement(
            "SELECT add_compression_policy('events', INTERVAL '{$compressAfter} days', if_not_exists => true)"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Remove policies; leaving the table as a (now plain) table is safe.
        DB::statement("SELECT remove_compression_policy('events', if_exists => true)");
        DB::statement("SELECT remove_retention_policy('events', if_exists => true)");
        DB::statement('ALTER TABLE events SET (timescaledb.compress = false)');
        // Note: reverting a hypertable back to a plain table is non-trivial and
        // rarely needed; the policies above are the meaningful, reversible part.
    }
};

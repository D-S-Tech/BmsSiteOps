<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Events time-series storage (TimescaleDB)
    |--------------------------------------------------------------------------
    |
    | The events table is a time-series hot path. On PostgreSQL with the
    | TimescaleDB extension it is converted to a hypertable with retention and
    | compression policies. These knobs tune those policies. They have no
    | effect on non-PostgreSQL connections (e.g. the SQLite test database),
    | where events remains an ordinary table.
    |
    */

    'events' => [
        // Drop event chunks older than this many days (retention policy).
        'retention_days' => (int) env('EVENTS_RETENTION_DAYS', 90),

        // Compress event chunks older than this many days (compression policy).
        'compression_after_days' => (int) env('EVENTS_COMPRESSION_AFTER_DAYS', 7),

        // Hypertable chunk size.
        'chunk_interval_days' => (int) env('EVENTS_CHUNK_INTERVAL_DAYS', 7),
    ],

];

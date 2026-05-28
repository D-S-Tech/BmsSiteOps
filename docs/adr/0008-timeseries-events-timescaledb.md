# ADR 0008 — Time-series storage for events (TimescaleDB)

**Status:** Accepted
**Date:** 2026-05-28

## Context

The `events` table (introduced in Sprint 1) is the platform's highest-volume,
most write-heavy table. Every collector poll across every source produces
events: TRMM alerts, Niagara point readings, BACnet present-values. At even a
modest fleet of sites polling on a 60-second interval, this table grows
continuously and is queried almost exclusively by time range and site:
"what happened at this site in the last 24 hours", "show the severity timeline
for this device this week".

Three forces:

- **Unbounded growth.** Telemetry accumulates forever unless actively managed.
  A plain table needs manual pruning and bloats indexes over time.
- **Time-range query shape.** Nearly every read is `WHERE site_id = ? AND
  occurred_at BETWEEN ? AND ?`. This is exactly the access pattern time-series
  partitioning optimizes.
- **Cost of storage.** Old telemetry is rarely read but must be retained for a
  while for trend analysis and the AI Site Brief (Sprint 4). Compressing it
  keeps cost down without losing it prematurely.

## Decision

**Convert `events` to a TimescaleDB hypertable on PostgreSQL, partitioned on
`occurred_at`, with retention and compression policies.** Keep it an ordinary
table everywhere else.

- Partition column: `occurred_at`, weekly chunks (configurable).
- The primary key becomes `(id, occurred_at)` so it includes the partition
  column, as TimescaleDB requires; Eloquent continues to treat `id` as the
  model key.
- Retention policy drops chunks older than `EVENTS_RETENTION_DAYS` (default 90).
- Compression policy compresses chunks older than
  `EVENTS_COMPRESSION_AFTER_DAYS` (default 7), segmented by `site_id`.
- The migration is guarded by a driver check: on SQLite (tests, local dev) it
  is a no-op and `events` stays a plain table.

## Consequences

**Positive**

- **Time-range reads stay fast as data grows.** Chunk exclusion means a 24-hour
  query never scans years of history.
- **Storage is self-managing.** Retention drops old chunks automatically;
  compression shrinks warm data. No cron-driven `DELETE` churning the table.
- **No application changes.** The hypertable is transparent to Eloquent and the
  REST API — same table name, same columns, same queries.
- **Tests stay driver-light.** CI runs on in-memory SQLite; the no-op guard
  keeps the suite fast and dependency-free while the production path is real.

**Negative / risks**

- **Not exercised in CI.** Because CI uses SQLite, the TimescaleDB path is not
  automatically verified. It is written per the TimescaleDB documentation and
  flagged in the migration as requiring validation against a real TimescaleDB
  instance before production use. This is the same honesty posture taken for
  the experimental BACnet wiring and Fox session.
- **Operational dependency.** Production PostgreSQL must have the TimescaleDB
  extension available. Documented in the deployment runbook.
- **Hypertable constraints.** Foreign keys *to* the events hypertable are not
  supported by TimescaleDB; any future "acknowledgement"-style feature must
  avoid a hard FK onto events (store the event id without a constraint, or
  attach operator state to devices/sites instead).
- **PK change.** Folding `occurred_at` into the primary key is a one-way schema
  change on production; the down() migration reverts the policies but not the
  hypertable itself, which is rarely needed.

## Alternatives considered

- **Plain table + scheduled pruning.** Simple, but index bloat and full-table
  scans on time ranges degrade as data grows; pruning `DELETE`s are expensive.
- **Partitioned table via native PostgreSQL declarative partitioning.** Works,
  but TimescaleDB adds automatic chunk management, compression, and retention
  policies on top of the same idea with far less hand-rolled SQL.
- **Separate time-series database (InfluxDB, Prometheus).** Splits the data
  store, complicates joins to the relational registry, and adds an operational
  component. Keeping events in PostgreSQL alongside the registry is simpler and
  sufficient at this scale.

## See also

- [ADR 0003 — Stack choices](./0003-stack-choices.md) — chose PostgreSQL.
- [`config/bmssiteops.php`](../../apps/api/config/bmssiteops.php) — retention,
  compression, and chunk-interval knobs.
- The events hypertable migration:
  `apps/api/database/migrations/2026_05_28_110000_convert_events_to_hypertable.php`.

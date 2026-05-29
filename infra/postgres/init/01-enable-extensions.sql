-- =============================================================================
-- BmsSiteOps - PostgreSQL extension initialization (Sprint 10)
-- =============================================================================
--
-- Runs ONCE on first start of the postgres container, courtesy of postgres's
-- /docker-entrypoint-initdb.d/ convention. Idempotent (every CREATE
-- EXTENSION uses IF NOT EXISTS) so re-running by hand is also safe.
--
-- After Sprint 10, the production stack uses timescale/timescaledb-ha:pg16
-- as the postgres image; both timescaledb and pgvector are pre-installed
-- at the OS level, so these CREATE EXTENSION calls just register them
-- inside the BmsSiteOps database. The Sprint 3 hypertable conversion and
-- Sprint 8.2 vector(768) column conversion then run cleanly without any
-- per-host apt-install step.
--
-- Mounted into the container at:
--   /docker-entrypoint-initdb.d/01-enable-extensions.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- TimescaleDB - time-series hypertables for events (Sprint 3)
-- ---------------------------------------------------------------------------
-- Adds the timescaledb schema + the create_hypertable() function family.
-- shared_preload_libraries already includes timescaledb in the base image's
-- postgresql.conf, so the extension loads at startup; this just makes it
-- visible inside the bmssiteops database.
CREATE EXTENSION IF NOT EXISTS timescaledb;

-- ---------------------------------------------------------------------------
-- pgvector - vector(768) column + HNSW index for RAG (Sprint 8.2)
-- ---------------------------------------------------------------------------
-- Adds the vector type and the cosine/euclidean/inner-product distance
-- operators (<=>, <->, <#>). Used by the embedding_pg mirror column on
-- document_chunks; populated by the BEFORE INSERT/UPDATE trigger from
-- migration 2026_05_28_180100_add_pgvector_column_to_chunks.
CREATE EXTENSION IF NOT EXISTS vector;

-- ---------------------------------------------------------------------------
-- Sanity output for the postgres logs
-- ---------------------------------------------------------------------------
-- Operators watching `docker compose logs postgres` on first start see this
-- confirmation; verify the same later with `make pg-ext-check`.
DO $$
BEGIN
    RAISE NOTICE 'BmsSiteOps extensions enabled: %, %',
        (SELECT extname || ' ' || extversion FROM pg_extension WHERE extname = 'timescaledb'),
        (SELECT extname || ' ' || extversion FROM pg_extension WHERE extname = 'vector');
END
$$;

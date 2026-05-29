<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a pgvector-typed mirror column + auto-sync trigger to document_chunks.
 *
 * The application code keeps writing JSON-serialized floats to the TEXT
 * column `embedding` (portable to SQLite, no app-side change required).
 * On PostgreSQL a BEFORE INSERT/UPDATE trigger casts that JSON to
 * pgvector's vector(N) type and stores it in the new `embedding_pg` column,
 * so VectorSearch::topK can use `embedding_pg <-> queryVector` SQL ordering
 * (10-100x faster than the in-memory cosine pass once chunk counts reach
 * the low thousands).
 *
 * Vector dimension is 768 — matches Ollama nomic-embed-text default
 * (Sprint 7.2 EmbeddingClient seam). If you switch embedding model to
 * something with a different dimension (text-embedding-3-small is 1536,
 * voyage-2 is 1024), the column must be re-typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // 1. Add the vector column (nullable; populated by trigger).
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding_pg vector(768)');

        // 2. Sync function — converts JSON text to pgvector format.
        //    pgvector parses strings like "[0.1,0.2,...]" directly; PHP's
        //    json_encode of a float array produces exactly that format.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_embedding_to_pgvector()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.embedding IS NOT NULL THEN
                    BEGIN
                        NEW.embedding_pg = NEW.embedding::vector;
                    EXCEPTION WHEN OTHERS THEN
                        -- Malformed embedding text -> leave embedding_pg null
                        -- so the chunk just doesn't participate in vector
                        -- search, rather than aborting the whole insert.
                        NEW.embedding_pg = NULL;
                    END;
                ELSE
                    NEW.embedding_pg = NULL;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // 3. Trigger fires on insert and whenever embedding column changes.
        DB::statement(<<<'SQL'
            CREATE TRIGGER document_chunks_sync_embedding_trigger
            BEFORE INSERT OR UPDATE OF embedding ON document_chunks
            FOR EACH ROW EXECUTE FUNCTION sync_embedding_to_pgvector()
        SQL);

        // 4. Backfill — existing chunks (if any) have JSON in `embedding`
        //    but a NULL in `embedding_pg` until something writes them.
        //    The UPDATE below fires the trigger and populates the column.
        DB::statement(<<<'SQL'
            UPDATE document_chunks
            SET embedding = embedding
            WHERE embedding IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }
        DB::statement('DROP TRIGGER IF EXISTS document_chunks_sync_embedding_trigger ON document_chunks');
        DB::statement('DROP FUNCTION IF EXISTS sync_embedding_to_pgvector()');
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropColumn('embedding_pg');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document chunks — the searchable / embeddable units.
 *
 * The `embedding` column is intentionally TEXT (JSON-serialized float array)
 * for portability between SQLite (CI) and PostgreSQL (production). A future
 * deployment-time optimization is to install pgvector and migrate this column
 * to a `vector(N)` type; the application-level interface (a JSON-friendly
 * VectorStore abstraction in 7.2) stays the same. Same posture as the
 * TimescaleDB hypertable conversion (ADR 0008).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Order within the document, starting at 0.
            $table->unsignedInteger('position');

            $table->text('content');

            // Optional model-reported token count (worker may set).
            $table->unsignedInteger('token_count')->nullable();

            // JSON-serialized float vector. Null until the worker embeds it.
            $table->text('embedding')->nullable();

            // Which model produced the embedding (for invalidating when we
            // switch models).
            $table->string('embedding_model', 100)->nullable();

            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_id', 'position']);
            $table->index(['tenant_id', 'embedded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};

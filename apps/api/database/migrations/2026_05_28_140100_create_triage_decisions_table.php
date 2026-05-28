<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of triage matches.
 *
 * NOTE on event_id: this references a row in `events`, which on PostgreSQL is a
 * TimescaleDB hypertable. TimescaleDB does not support foreign keys *to*
 * hypertables (see ADR 0008), so event_id is a plain indexed bigint here, not
 * a constrained FK. Referential integrity is enforced at the application
 * layer (we only insert decisions for events we just created).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Plain bigint — no FK to events (TimescaleDB hypertable constraint).
            $table->unsignedBigInteger('event_id');

            // Triage_rules is a regular table, so a real FK is fine here.
            $table->foreignId('rule_id')->constrained('triage_rules')->cascadeOnDelete();

            // Denormalized site_id for fast per-site audit queries.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('action', 32);   // TriageAction value snapshot
            $table->string('status', 16);   // TriageStatus value
            $table->text('notes')->nullable();
            $table->json('result')->nullable();

            $table->timestamp('occurred_at');
            // Append-only: created_at = persistence time, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'site_id', 'occurred_at']);
            $table->index(['tenant_id', 'event_id']);
            $table->index(['rule_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_decisions');
    }
};

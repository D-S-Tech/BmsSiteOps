<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // Denormalized from device for triage / site-brief queries.
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('metric', 128);
            $table->string('value', 1024)->nullable();
            $table->string('severity', 16)->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            // Append-only: created_at = ingestion time, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Hot query paths: per-site time range, per-device timeline.
            $table->index(['tenant_id', 'site_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
            $table->index(['tenant_id', 'severity', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // The generated natural-language brief.
            $table->text('summary');
            // Which model produced it (e.g. claude-sonnet-4-5).
            $table->string('model', 128);

            // The observation window this brief covers.
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Token counts and the data snapshot used to generate the brief.
            $table->json('metadata')->nullable();

            $table->timestamp('generated_at');
            // Append-only: created_at = persistence time, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Hot path: latest brief(s) for a site.
            $table->index(['tenant_id', 'site_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_briefs');
    }
};

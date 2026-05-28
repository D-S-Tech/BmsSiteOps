<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Q&A — every question + its answer is persisted for audit, history,
 * and future fine-tuning. Synchronous from the operator's perspective; the
 * controller orchestrates embed -> search -> generate and only responds
 * once the row is Ready (or Failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('question');
            $table->text('answer')->nullable();

            $table->string('status', 16);  // QuestionStatus value
            $table->text('error')->nullable();

            // Generation model (LLM that produced the answer). The embedding
            // model + tokens + citations live in metadata.
            $table->string('model', 100)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('asked_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'asked_at']);
            $table->index(['status', 'asked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};

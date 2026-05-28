<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Audit: who requested it. Nullable so a future system-generated
            // (e.g. recurring AI-suggested remediation) script can have no user.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 200);
            $table->text('prompt');
            $table->string('language', 32);    // ScriptLanguage value
            $table->string('status', 16);      // ScriptStatus value

            // Populated by the worker when status -> ready.
            $table->longText('content')->nullable();
            $table->string('model', 100)->nullable();
            $table->text('error')->nullable();

            // Tokens, durations, prompt digests, etc.
            $table->json('metadata')->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('claimed_at')->nullable();   // when a worker took it
            $table->timestamp('generated_at')->nullable(); // when generation finished
            $table->timestamps();

            // Hot path: list-by-tenant newest-first, claim-next by status.
            $table->index(['tenant_id', 'requested_at']);
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};

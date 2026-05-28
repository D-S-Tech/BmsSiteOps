<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Operator-facing identity.
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Match conditions — all nullable; any null/blank condition is
            // treated as a wildcard. A rule matches an event iff every
            // non-null condition matches.
            $table->string('severity_match', 16)->nullable();   // critical | warning | info
            $table->string('kind_match', 32)->nullable();       // trmm | niagara | bacnet
            $table->string('metric_pattern', 200)->nullable();  // fnmatch glob (e.g. "disk*")
            $table->string('value_contains', 200)->nullable();  // case-insensitive substring

            // What to do on match.
            $table->string('action', 32);                       // TriageAction value
            $table->json('action_params')->nullable();          // optional per-action knobs

            // Lower number = higher priority. Highest-priority enabled rule wins.
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_rules');
    }
};

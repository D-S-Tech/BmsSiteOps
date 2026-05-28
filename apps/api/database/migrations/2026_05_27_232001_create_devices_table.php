<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            // Denormalized from source for fast site-level queries.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 255);
            $table->string('name', 255);
            $table->string('type', 64)->nullable();
            $table->string('status', 16)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // A device is unique within its source.
            $table->unique(['source_id', 'external_id']);
            $table->index(['tenant_id', 'site_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

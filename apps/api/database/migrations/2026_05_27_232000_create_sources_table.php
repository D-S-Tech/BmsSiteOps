<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('name', 200);
            $table->string('base_url', 500)->nullable();
            // Encrypted at rest via the model's encrypted:array cast.
            $table->text('credentials')->nullable();
            $table->unsignedInteger('poll_interval_seconds')->default(60);
            $table->boolean('is_active')->default(true);
            $table->string('last_status', 16)->default('never');
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Tenant-isolation rule: composite unique index leads with tenant_id.
            $table->unique(['tenant_id', 'site_id', 'kind', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};

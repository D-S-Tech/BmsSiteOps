<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name', 200);
            $table->string('address', 255)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Tenant-isolation rule: every composite unique index includes tenant_id first.
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};

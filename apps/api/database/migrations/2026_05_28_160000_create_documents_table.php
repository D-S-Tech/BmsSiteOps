<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Optional site association — a document may be tenant-wide
            // (e.g. "company HVAC commissioning standard") or scoped to a site
            // (e.g. "80 Pine St mechanical room sequence of operations").
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            // Audit
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 200);

            // Where the text came from. 'manual' = pasted into form,
            // 'upload' = parsed from a PDF/file (extension), 'brief' = derived
            // from an AI Site Brief, 'spec' = vendor spec sheet, etc.
            $table->string('source_type', 32)->default('manual');
            $table->string('source_ref', 500)->nullable();  // URL or original filename

            // The full text, before chunking. longText (4GB max) for big docs.
            $table->longText('content');

            $table->string('status', 16);  // DocumentStatus value
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'site_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
